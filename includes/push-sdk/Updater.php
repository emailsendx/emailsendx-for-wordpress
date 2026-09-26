<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * Plugs the licence server into WordPress's own update system: the Updates
 * screen, the "View details" popup, one-click and automatic updates.
 *
 * Every package is checked before WordPress unzips it: the SDK fetches a
 * fresh signed manifest, downloads the zip itself and compares its SHA-384
 * with the one the server signed. A tampered or swapped zip is refused.
 */
final class Updater
{
    /** @var Client */
    private $client;
    /** @var string */
    private $cacheKey;

    /** How long an update check is reused (WordPress checks roughly twice a day). */
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->cacheKey = 'tdgpush_upd_' . md5($client->slug());
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject']);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_upgrade'], 10, 2);
    }

    /** Drop cached update data so the next WordPress check asks the server again. */
    public function flush(): void
    {
        delete_site_transient($this->cacheKey);
        delete_site_transient('update_plugins');
    }

    /** @return array<string,mixed>|null The cached server answer, without a network call. */
    public function cached(): ?array
    {
        $cached = get_site_transient($this->cacheKey);
        return is_array($cached) && empty($cached['failed']) ? $cached : null;
    }

    /**
     * Latest server answer, cached. `$fresh` bypasses the cache (force-check,
     * push pings, right before installing).
     *
     * @return array<string,mixed>|null
     */
    public function state(bool $fresh = false): ?array
    {
        if (!$fresh) {
            $cached = get_site_transient($this->cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $body = $this->client->license()->refresh();
        if (is_wp_error($body)) {
            // Cache the failure briefly so a down server doesn't slow every admin page.
            set_site_transient($this->cacheKey, ['update' => null, 'failed' => true], 30 * MINUTE_IN_SECONDS);
            return null;
        }
        set_site_transient($this->cacheKey, $body, self::CACHE_TTL);
        return $body;
    }

    /** @param mixed $transient @return mixed */
    public function inject($transient)
    {
        if (!is_object($transient) || !$this->client->license()->is_activated()) {
            return $transient;
        }
        $force = is_admin() && isset($_GET['force-check']); // phpcs:ignore WordPress.Security.NonceVerification
        $state = $this->state($force);
        $update = is_array($state) ? ($state['update'] ?? null) : null;
        $basename = $this->client->basename();
        $installed = $this->client->version();

        $entry = [
            'id' => $basename,
            'slug' => $this->client->slug(),
            'plugin' => $basename,
            'url' => (string) ($state['product']['homepage'] ?? ''),
            'icons' => $this->icons($state),
            'banners' => $this->banners($state),
        ];

        if (is_array($update) && version_compare((string) $update['version'], $installed, '>')) {
            $transient->response[$basename] = (object) ($entry + [
                'new_version' => (string) $update['version'],
                'package' => (string) $update['package'],
                'requires' => (string) ($update['requires_wp'] ?? ''),
                'requires_php' => (string) ($update['requires_php'] ?? ''),
                'tested' => (string) ($update['tested_wp'] ?? ''),
            ]);
            unset($transient->no_update[$basename]);
        } else {
            // Listing the plugin under no_update keeps the "Enable auto-updates" link available.
            $transient->no_update[$basename] = (object) ($entry + ['new_version' => $installed, 'package' => '']);
            unset($transient->response[$basename]);
        }
        return $transient;
    }

    /**
     * The "View details" popup.
     *
     * @param mixed $result
     * @param object $args
     * @return mixed
     */
    public function details($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->client->slug()) {
            return $result;
        }
        $state = $this->state();
        $product = is_array($state) ? ($state['product'] ?? []) : [];
        $update = is_array($state) ? ($state['update'] ?? null) : null;

        $description = !empty($product['description_html'])
            ? (string) $product['description_html']
            : wpautop(esc_html((string) ($product['description'] ?? '')));
        $links = [];
        if (!empty($product['docs'])) {
            $links[] = '<a href="' . esc_url((string) $product['docs']) . '" target="_blank" rel="noopener">Documentation</a>';
        }
        if (!empty($product['support'])) {
            $links[] = '<a href="' . esc_url((string) $product['support']) . '" target="_blank" rel="noopener">Support</a>';
        }
        if ($links) {
            $description .= '<p>' . implode(' · ', $links) . '</p>';
        }
        $sections = ['description' => wp_kses_post($description)];
        if (!empty($product['installation_html'])) {
            $sections['installation'] = wp_kses_post((string) $product['installation_html']);
        }
        if (!empty($product['faq_html'])) {
            $sections['faq'] = wp_kses_post((string) $product['faq_html']);
        }
        if (is_array($update) && !empty($update['changelog_html'])) {
            $sections['changelog'] = wp_kses_post((string) $update['changelog_html']);
        }
        $authorName = (string) ($product['author'] ?? $this->client->header('Author') ?? '');
        $author = !empty($product['author_url']) && $authorName !== ''
            ? '<a href="' . esc_url((string) $product['author_url']) . '">' . esc_html($authorName) . '</a>'
            : esc_html($authorName);
        return (object) [
            'name' => (string) ($product['name'] ?? $this->client->name()),
            'slug' => $this->client->slug(),
            'version' => is_array($update) ? (string) $update['version'] : $this->client->version(),
            'author' => $author,
            'homepage' => (string) ($product['homepage'] ?? ''),
            'requires' => is_array($update) ? (string) ($update['requires_wp'] ?? '') : '',
            'requires_php' => is_array($update) ? (string) ($update['requires_php'] ?? '') : '',
            'tested' => is_array($update) ? (string) ($update['tested_wp'] ?? '') : (string) ($product['tested_wp'] ?? ''),
            'last_updated' => is_array($update) ? (string) ($update['released_at'] ?? '') : '',
            'sections' => $sections,
            'banners' => $this->banners($state),
            'icons' => $this->icons($state),
            'download_link' => is_array($update) ? (string) $update['package'] : '',
        ];
    }

    /**
     * Download and verify our package instead of letting WordPress fetch the
     * (possibly stale) URL from its transient.
     *
     * @param bool|string|\WP_Error $reply
     * @param array<string,mixed> $hookExtra
     * @return bool|string|\WP_Error Path to the verified zip, an error, or $reply untouched.
     */
    public function download($reply, string $package, $upgrader, $hookExtra = [])
    {
        if ($reply !== false || !$this->isOurs($package, is_array($hookExtra) ? $hookExtra : [])) {
            return $reply;
        }
        $state = $this->state(true);
        $update = is_array($state) ? ($state['update'] ?? null) : null;
        if (!is_array($update)) {
            return new \WP_Error('tdgpush_no_update', 'No update is available for this site right now. Check the licence is active.');
        }

        $manifest = Token::verify((string) $update['manifest'], $this->client->publicKey(), $this->client->slug());
        $instance = (string) $this->client->store()->get('instance_id');
        if (
            !$manifest
            || ($manifest['typ'] ?? '') !== 'update'
            || ($manifest['sub'] ?? '') !== $instance
            || ($manifest['version'] ?? '') !== $update['version']
            || ($manifest['sha384'] ?? '') !== $update['sha384']
        ) {
            return new \WP_Error('tdgpush_manifest', 'The update could not be verified (bad signature). It was not installed.');
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // WordPress refuses private hosts and odd ports for downloads; lift that
        // only for a local development API, and only for this one request.
        $local = $this->client->isLocalApi();
        $external = '__return_true';
        $port = (int) wp_parse_url((string) $update['package'], PHP_URL_PORT);
        $ports = static function ($p) use ($port) {
            return array_merge((array) $p, [$port]);
        };
        if ($local) {
            add_filter('http_request_host_is_external', $external);
            add_filter('http_allowed_safe_ports', $ports);
        }
        $file = download_url((string) $update['package'], 300);
        if ($local) {
            remove_filter('http_request_host_is_external', $external);
            remove_filter('http_allowed_safe_ports', $ports);
        }
        if (is_wp_error($file)) {
            return $file;
        }
        $expected = base64_decode((string) $manifest['sha384'], true);
        $actual = hash_file('sha384', $file, true);
        if ($expected === false || $actual === false || !hash_equals($expected, $actual)) {
            wp_delete_file($file);
            return new \WP_Error('tdgpush_checksum', 'The downloaded update did not match its signed checksum. It was not installed.');
        }
        return $file;
    }

    /**
     * @param \WP_Upgrader $upgrader
     * @param array<string,mixed> $extra
     */
    public function after_upgrade($upgrader, $extra): void
    {
        $plugins = (array) ($extra['plugins'] ?? (isset($extra['plugin']) ? [$extra['plugin']] : []));
        if (($extra['type'] ?? '') === 'plugin' && in_array($this->client->basename(), $plugins, true)) {
            delete_site_transient($this->cacheKey);
        }
    }

    /** @param array<string,mixed> $extra */
    private function isOurs(string $package, array $extra): bool
    {
        // Every plugin on this server shares the download URL prefix, so the URL
        // alone can't tell two DevGarden plugins on one site apart.
        if (isset($extra['plugin'])) {
            return $extra['plugin'] === $this->client->basename();
        }
        $state = $this->state();
        $offered = is_array($state) && is_array($state['update'] ?? null) ? (string) ($state['update']['package'] ?? '') : '';
        return $offered !== '' && $package === $offered;
    }

    /** @param array<string,mixed>|null $state @return array<string,string> */
    private function icons($state): array
    {
        $icon = is_array($state) ? (string) ($state['product']['icon'] ?? '') : '';
        return $icon ? ['1x' => $icon, '2x' => $icon, 'default' => $icon] : [];
    }

    /** @param array<string,mixed>|null $state @return array<string,string> */
    private function banners($state): array
    {
        $banner = is_array($state) ? (string) ($state['product']['banner'] ?? '') : '';
        return $banner ? ['low' => $banner, 'high' => $banner] : [];
    }
}
