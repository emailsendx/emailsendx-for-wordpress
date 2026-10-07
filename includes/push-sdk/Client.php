<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * DevGarden Push client for one plugin.
 *
 *   $push = \YourPlugin\Push\Client::init([
 *       'file'       => __FILE__,                     // main plugin file
 *       'slug'       => 'your-plugin',                // product slug on the server
 *       'public_key' => 'base64 Ed25519 public key',  // Admin → Settings on the server
 *       'api'        => 'https://push.thedevgarden.dev',
 *       'menu'       => ['parent' => 'options-general.php', 'title' => 'Your Plugin licence'],
 *   ]);
 *   if ($push->license()->is_valid()) { ...premium features... }
 *
 * For local development the API host can be overridden with a constant named
 * after the slug, e.g. YOUR_PLUGIN_PUSH_API. Plain http is only accepted for
 * local hosts.
 */
final class Client
{
    const SDK_VERSION = '1.2.1';

    /** @var array<string,Client> */
    private static $instances = [];

    /** @var array<string,mixed> */
    private $config;
    /** @var array<string,string>|null */
    private $headers;
    /** @var Store */
    private $store;
    /** @var License */
    private $license;
    /** @var Updater */
    private $updater;
    /** @var Api */
    private $api;
    /** @var Connect */
    private $connect;
    /** @var Push */
    private $push;

    /** @param array<string,mixed> $config */
    public static function init(array $config): self
    {
        $slug = (string) ($config['slug'] ?? '');
        if (isset(self::$instances[$slug])) {
            return self::$instances[$slug];
        }
        foreach (['file', 'slug', 'public_key', 'api'] as $required) {
            if (empty($config[$required])) {
                throw new \InvalidArgumentException("DevGarden Push: missing config '$required'.");
            }
        }
        return self::$instances[$slug] = new self($config);
    }

    /** The client a plugin already created with init(), e.g. from theme or add-on code. */
    public static function get(string $slug): ?self
    {
        return self::$instances[$slug] ?? null;
    }

    /** @param array<string,mixed> $config */
    private function __construct(array $config)
    {
        $this->config = $config;
        $this->store = new Store($this->slug());
        $this->api = new Api($this);
        $this->license = new License($this, $this->store);
        $this->updater = new Updater($this);
        $this->connect = new Connect($this);
        $this->push = new Push($this);
        $this->push->register();

        $this->updater->register();
        if (!empty($config['menu'])) {
            (new AdminPage($this))->register();
        }

        if ($this->isFree()) {
            // Free plugins register themselves: first wp-admin visit after install or update.
            add_action('admin_init', [$this, 'maybeRegister']);
        }

        $hook = $this->cronHook();
        add_action($hook, [$this, 'daily']);
        add_action('init', function () use ($hook) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + wp_rand(60, HOUR_IN_SECONDS), 'daily', $hook);
            }
        });
        register_deactivation_hook($this->file(), function () use ($hook) {
            wp_clear_scheduled_hook($hook);
        });
    }

    public function isFree(): bool
    {
        return !empty($this->config['free']);
    }

    /** Free plugins: register quietly if this site isn't registered yet (retry at most hourly). */
    public function maybeRegister(): void
    {
        if ($this->license->is_activated()) {
            return;
        }
        $retry = 'tdgpush_reg_' . md5($this->slug());
        if (get_transient($retry)) {
            return;
        }
        set_transient($retry, 1, HOUR_IN_SECONDS);
        if ($this->license->register() === true) {
            delete_transient($retry);
        }
    }

    /** Daily check-in: refreshes the signed licence and the update offer. */
    public function daily(): void
    {
        if ($this->store->token() !== null) {
            $this->updater->state(true);
        }
    }

    public function license(): License
    {
        return $this->license;
    }

    public function updater(): Updater
    {
        return $this->updater;
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function api(): Api
    {
        return $this->api;
    }

    public function connect(): Connect
    {
        return $this->connect;
    }

    public function push(): Push
    {
        return $this->push;
    }

    /** Whether the site owner turned on WordPress auto-updates for this plugin. */
    public function autoUpdateEnabled(): bool
    {
        if (!function_exists('wp_is_auto_update_enabled_for_type')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $on = wp_is_auto_update_enabled_for_type('plugin')
            && in_array($this->basename(), (array) get_site_option('auto_update_plugins', []), true);
        return (bool) apply_filters('auto_update_plugin', $on, (object) ['plugin' => $this->basename(), 'slug' => $this->slug()]);
    }

    /** The version on disk right now (after an update the loaded header is stale). */
    public function freshVersion(): string
    {
        $this->headers = null;
        unset($this->config['version']);
        return $this->version();
    }

    public function slug(): string
    {
        return (string) $this->config['slug'];
    }

    public function file(): string
    {
        return (string) $this->config['file'];
    }

    public function basename(): string
    {
        return plugin_basename($this->file());
    }

    public function publicKey(): string
    {
        return (string) $this->config['public_key'];
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->header('Name') ?? $this->slug());
    }

    public function version(): string
    {
        return (string) ($this->config['version'] ?? $this->header('Version') ?? '0');
    }

    public function header(string $key): ?string
    {
        if ($this->headers === null) {
            $this->headers = get_file_data($this->file(), ['Name' => 'Plugin Name', 'Version' => 'Version', 'Author' => 'Author']);
        }
        $v = $this->headers[$key] ?? '';
        return $v !== '' ? $v : null;
    }

    public function apiBase(): string
    {
        $base = $this->configuredApi();
        if (strpos($base, 'https://') !== 0 && !($this->isLocalApi() && strpos($base, 'http://') === 0)) {
            // Never send licence keys over plain http to a public host.
            return 'https://invalid.invalid';
        }
        return $base;
    }

    /**
     * Where the customer's browser goes (sign-in, connect, portal). Same as the
     * API unless overridden, e.g. YOUR_PLUGIN_PUSH_PORTAL in local development
     * where the API host is only reachable from inside Docker.
     */
    public function portalBase(): string
    {
        $const = strtoupper(str_replace('-', '_', $this->slug())) . '_PUSH_PORTAL';
        $base = defined($const) ? rtrim((string) constant($const), '/') : $this->apiBase();
        return strpos($base, 'https://') === 0 || $this->isLocalApi() ? $base : 'https://invalid.invalid';
    }

    /** True when the API is a development host (local testing against a dev server). */
    public function isLocalApi(): bool
    {
        $host = (string) wp_parse_url($this->configuredApi(), PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true) || substr($host, -5) === '.test';
    }

    private function configuredApi(): string
    {
        $const = strtoupper(str_replace('-', '_', $this->slug())) . '_PUSH_API';
        return rtrim(defined($const) ? (string) constant($const) : (string) $this->config['api'], '/');
    }

    public function userAgent(): string
    {
        return sprintf('DevGardenPush/%s %s/%s; %s', self::SDK_VERSION, $this->slug(), $this->version(), home_url());
    }

    /** @return array<string,mixed> Sent with every call so the dashboard shows real versions. */
    public function environment(): array
    {
        global $wp_version;
        return [
            'plugin_version' => $this->version(),
            'wp_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'push_url' => $this->push->url(),
            'auto_update' => $this->autoUpdateEnabled(),
            'site' => $this->siteInfo(),
        ];
    }

    /**
     * About the site, for the dashboard's site page. Nothing personal: no
     * users, emails or content.
     *
     * @return array<string,string|bool>
     */
    public function siteInfo(): array
    {
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '';
        $info = [
            'name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'locale' => (string) get_locale(),
            'multisite' => is_multisite(),
            'theme' => $theme ? (string) $theme->get('Name') : '',
            'theme_version' => $theme ? (string) $theme->get('Version') : '',
            'woocommerce' => defined('WC_VERSION') ? (string) constant('WC_VERSION') : '',
            'server' => substr($server, 0, 80),
            'memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : (string) ini_get('memory_limit'),
        ];
        return array_filter($info, static function ($v) {
            return $v !== '';
        });
    }

    private function cronHook(): string
    {
        return 'tdgpush_daily_' . str_replace('-', '_', $this->slug());
    }
}
