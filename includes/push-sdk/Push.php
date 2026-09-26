<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * "Push now" receiver and hourly heartbeat.
 *
 * The licence server POSTs a short-lived Ed25519-signed ping to
 * /wp-json/tdgpush/v1/<slug>/push. After verifying it (signature, audience,
 * this site's instance id, expiry, not replayed) the site refreshes its update
 * offer and, in a WP-Cron event, lets WordPress's own automatic updater
 * install it — which respects the site owner's auto-update setting and file
 * permissions, and keeps the plugin active. The outcome is reported back.
 */
final class Push
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action($this->hook('install'), [$this, 'install']);
        add_action($this->hook('hourly'), [$this, 'heartbeat']);
        add_action('init', function () {
            if (!wp_next_scheduled($this->hook('hourly'))) {
                wp_schedule_event(time() + wp_rand(60, HOUR_IN_SECONDS), 'hourly', $this->hook('hourly'));
            }
        });
        register_deactivation_hook($this->client->file(), function () {
            wp_clear_scheduled_hook($this->hook('hourly'));
        });
    }

    public function url(): string
    {
        return rest_url('tdgpush/v1/' . $this->client->slug() . '/push');
    }

    public function routes(): void
    {
        register_rest_route('tdgpush/v1', '/' . $this->client->slug() . '/push', [
            'methods' => 'POST',
            'callback' => [$this, 'receive'],
            // Authorisation is the signed token in the body, verified below.
            'permission_callback' => '__return_true',
        ]);
    }

    /** @return \WP_REST_Response */
    public function receive(\WP_REST_Request $request)
    {
        $payload = Token::verify((string) $request->get_param('token'), $this->client->publicKey(), $this->client->slug());
        $instance = (string) $this->client->store()->get('instance_id');
        if (!$payload || ($payload['typ'] ?? '') !== 'push' || $instance === '' || ($payload['sub'] ?? '') !== $instance) {
            return new \WP_REST_Response(['ok' => false, 'code' => 'invalid_ping', 'message' => 'Ping signature or target is invalid.'], 403);
        }
        $jti = (string) ($payload['jti'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti)) {
            return new \WP_REST_Response(['ok' => false, 'code' => 'invalid_ping', 'message' => 'Ping id is invalid.'], 403);
        }

        // Each ping acts once; a replay inside its 10-minute lifetime is acknowledged but ignored.
        $seen = (array) get_option($this->option('seen'), []);
        if (in_array($jti, $seen, true)) {
            return new \WP_REST_Response(['ok' => true, 'duplicate' => true, 'auto_update' => $this->client->autoUpdateEnabled()], 200);
        }
        $seen[] = $jti;
        update_option($this->option('seen'), array_slice($seen, -50), false);

        $this->client->updater()->flush();
        wp_schedule_single_event(time(), $this->hook('install'), [$jti]);
        spawn_cron();

        return new \WP_REST_Response([
            'ok' => true,
            'auto_update' => $this->client->autoUpdateEnabled(),
            'version' => $this->client->version(),
        ], 202);
    }

    /** WP-Cron: install the pushed update if the site allows automatic updates for this plugin. */
    public function install(string $jti): void
    {
        foreach (['file.php', 'misc.php', 'plugin.php', 'update.php', 'class-wp-upgrader.php'] as $inc) {
            require_once ABSPATH . 'wp-admin/includes/' . $inc;
        }
        if (!class_exists('WP_Automatic_Updater')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }

        $state = $this->client->updater()->state(true);
        $update = is_array($state) ? ($state['update'] ?? null) : null;
        if (!is_array($update) || !version_compare((string) $update['version'], $this->client->version(), '>')) {
            $this->report($jti, 'updated', 'Already on the latest version.');
            return;
        }

        wp_update_plugins(); // repopulates WordPress's update list through our filter
        $transient = get_site_transient('update_plugins');
        $item = is_object($transient) ? ($transient->response[$this->client->basename()] ?? null) : null;
        if (!$item) {
            $this->report($jti, 'failed', 'WordPress did not register the update.');
            return;
        }

        $updater = new \WP_Automatic_Updater();
        if ($updater->is_disabled()) {
            $this->report($jti, 'skipped', 'Automatic updates are disabled on this site.');
            return;
        }
        if (!$updater->should_update('plugin', $item, WP_PLUGIN_DIR)) {
            $this->report($jti, 'skipped', $this->client->autoUpdateEnabled()
                ? 'WordPress cannot write plugin files unattended on this host.'
                : 'Auto-updates are off for this plugin; the update is waiting in wp-admin.');
            return;
        }

        $result = $updater->update('plugin', $item);
        $now = $this->client->freshVersion();
        if (version_compare($now, (string) $update['version'], '>=')) {
            $this->client->updater()->state(true); // tell the server the new version
            $this->report($jti, 'updated', 'Installed ' . $now . '.');
        } else {
            $msg = is_wp_error($result) ? $result->get_error_message() : 'The update did not install.';
            $this->report($jti, 'failed', $msg);
        }
    }

    /**
     * Hourly: a cheap, unauthenticated "what's the latest version?" so a site
     * notices a release within the hour even if a push never reached it.
     */
    public function heartbeat(): void
    {
        if (!$this->client->license()->is_activated()) {
            return;
        }
        $channel = $this->client->license()->beta() ? 'beta' : 'stable';
        $latest = $this->client->api()->get('/api/v1/latest?product=' . rawurlencode($this->client->slug()) . '&channel=' . $channel);
        if (is_array($latest) && !empty($latest['version']) && version_compare((string) $latest['version'], $this->client->version(), '>')) {
            $this->client->updater()->flush();
            wp_update_plugins();
        }
    }

    private function report(string $jti, string $result, string $detail): void
    {
        $token = $this->client->store()->token();
        if ($token === null) {
            return;
        }
        $this->client->api()->post('/api/v1/push/report', [
            'jti' => $jti,
            'result' => $result,
            'detail' => $detail,
        ], $token);
    }

    private function hook(string $name): string
    {
        return 'tdgpush_' . $name . '_' . str_replace('-', '_', $this->client->slug());
    }

    private function option(string $name): string
    {
        return 'tdgpush_' . str_replace('-', '_', $this->client->slug()) . '_' . $name;
    }
}
