<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * The licence screen in wp-admin.
 *
 * Not activated: connect with one click (account sign-in) or paste a key.
 * Activated: status, plan, expiry, seats, update status, beta channel, and
 * deactivation. Styles are scoped under .tdgp so nothing leaks into wp-admin.
 */
final class AdminPage
{
    /** @var Client */
    private $client;
    /** @var string */
    private $page;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->page = $client->slug() . '-license';
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . $this->action(), [$this, 'handle']);
        add_action('admin_post_' . $this->action() . '_connected', [$this, 'connected']);
        add_action('admin_notices', [$this, 'notice']);
        add_filter('plugin_action_links_' . $this->client->basename(), [$this, 'actionLink']);
    }

    public function url(): string
    {
        $parent = (string) ($this->client->config()['menu']['parent'] ?? 'options-general.php');
        $base = strpos($parent, '.php') !== false ? $parent : 'admin.php';
        return admin_url($base . '?page=' . $this->page);
    }

    public function menu(): void
    {
        $menu = (array) $this->client->config()['menu'];
        $title = (string) ($menu['title'] ?? $this->client->name() . ' licence');
        add_submenu_page((string) ($menu['parent'] ?? 'options-general.php'), $title, $title, 'manage_options', $this->page, [$this, 'render']);
    }

    /** @param array<string,string> $links @return array<string,string> */
    public function actionLink(array $links): array
    {
        $label = $this->client->isFree() ? 'Updates' : ($this->client->license()->is_valid() ? 'Licence' : 'Activate licence');
        return ['tdgpush' => '<a href="' . esc_url($this->url()) . '">' . esc_html($label) . '</a>'] + $links;
    }

    public function notice(): void
    {
        if (!current_user_can('manage_options') || $this->client->isFree() || $this->client->license()->is_valid()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['plugins', 'dashboard', 'update-core'], true)) {
            return;
        }
        $status = $this->client->license()->status();
        $text = $status === 'inactive'
            ? sprintf('%s: activate your licence to receive updates.', $this->client->name())
            : sprintf('%s: your licence is %s. Updates are paused until it is active.', $this->client->name(), $status);
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html($text),
            esc_url($this->url()),
            esc_html($status === 'inactive' ? 'Activate now' : 'Manage licence')
        );
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to manage this licence.', 403);
        }
        check_admin_referer($this->action());
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        $license = $this->client->license();
        $result = true;

        if ($op === 'connect') {
            // Leaves the site: the browser goes to the licence server to sign in and approve.
            wp_redirect($this->client->connect()->start(admin_url('admin-post.php?action=' . $this->action() . '_connected')));
            exit;
        } elseif ($op === 'register') {
            $result = $license->register();
        } elseif ($op === 'activate') {
            $result = $license->activate(sanitize_text_field(wp_unslash((string) ($_POST['license_key'] ?? ''))));
        } elseif ($op === 'deactivate') {
            $license->deactivate();
        } elseif ($op === 'check') {
            $state = $this->client->updater()->state(true);
            $result = $state === null && $license->is_activated()
                ? new \WP_Error('tdgpush_check', (string) ($this->client->store()->get('last_error') ?: 'The check failed.'))
                : true;
            delete_site_transient('update_plugins');
        } elseif ($op === 'beta') {
            $license->set_beta(!empty($_POST['beta']));
            $this->client->updater()->flush();
        }

        $this->flash(is_wp_error($result) ? ['error', $result->get_error_message()] : ['success', $this->successMessage($op)]);
        wp_safe_redirect($this->url());
        exit;
    }

    /** Return leg of one-click connect. The `state` check inside finish() is the CSRF protection. */
    public function connected(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to manage this licence.', 403);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $result = $this->client->connect()->finish(
            sanitize_text_field(wp_unslash((string) ($_GET['code'] ?? ''))),
            sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? ''))),
            sanitize_key((string) ($_GET['error'] ?? ''))
        );
        // phpcs:enable
        $this->flash(is_wp_error($result) ? ['error', $result->get_error_message()] : ['success', 'Connected. This site is activated and will receive updates.']);
        wp_safe_redirect($this->url());
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $license = $this->client->license();
        $flash = get_transient($this->flashKey());
        delete_transient($this->flashKey());
        $status = $license->status();
        $activated = $license->is_activated();

        $this->styles();
        ?>
        <div class="wrap tdgp">
            <h1 class="tdgp-sr"><?php echo esc_html($this->client->name()); ?> licence</h1>

            <header class="tdgp-hero">
                <span class="tdgp-mark" aria-hidden="true"><?php echo $this->icon('sprout'); // static SVG ?></span>
                <div class="tdgp-hero-text">
                    <p class="tdgp-title"><?php echo esc_html($this->client->name()); ?></p>
                    <p class="tdgp-sub"><?php echo $this->client->isFree() ? 'Updates' : 'Licence &amp; updates'; ?> · v<?php echo esc_html($this->client->version()); ?></p>
                </div>
                <?php
                if ($this->client->isFree()) {
                    echo $activated
                        ? '<span class="tdgp-pill tdgp-pill-ok"><span class="tdgp-dot"></span>Free · updates on</span>'
                        : '<span class="tdgp-pill tdgp-pill-muted"><span class="tdgp-dot"></span>Free · not registered</span>';
                } else {
                    $this->statusPill($activated ? $status : 'inactive');
                }
                ?>
            </header>

            <?php if (is_array($flash)) : ?>
                <div class="tdgp-alert tdgp-alert-<?php echo $flash[0] === 'error' ? 'error' : 'ok'; ?>" role="<?php echo $flash[0] === 'error' ? 'alert' : 'status'; ?>">
                    <?php echo $this->icon($flash[0] === 'error' ? 'alert' : 'check'); // static SVG ?>
                    <span><?php echo esc_html((string) $flash[1]); ?></span>
                </div>
            <?php endif; ?>

            <?php
            if ($this->client->isFree()) {
                $activated ? $this->renderUpdates('active') : $this->renderRegister();
            } else {
                $activated ? $this->renderActive($status) : $this->renderActivate();
            }
            ?>

            <?php if (!$this->client->isFree()) : ?>
            <p class="tdgp-foot">
                Licences managed at
                <a href="<?php echo esc_url($this->client->portalBase() . '/dashboard'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) wp_parse_url($this->client->portalBase(), PHP_URL_HOST)); ?></a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderActivate(): void
    {
        ?>
        <section class="tdgp-card tdgp-activate">
            <div class="tdgp-activate-head">
                <span class="tdgp-icon-tile" aria-hidden="true"><?php echo $this->icon('key'); // static SVG ?></span>
                <h2>Activate <?php echo esc_html($this->client->name()); ?></h2>
                <p>Unlock automatic updates and premium features on this site.</p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php $this->hidden('connect'); ?>
                <button type="submit" class="tdgp-btn tdgp-btn-primary tdgp-btn-block">
                    <?php echo $this->icon('plug'); // static SVG ?> Connect with your account
                </button>
            </form>
            <p class="tdgp-hint tdgp-center">Sign in, pick a licence, and you're done — no copying keys.</p>

            <div class="tdgp-divider"><span>or paste a licence key</span></div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tdgp-keyform">
                <?php $this->hidden('activate'); ?>
                <label class="tdgp-sr" for="tdgp-key">Licence key</label>
                <input id="tdgp-key" class="tdgp-input" type="text" name="license_key" placeholder="TDG-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" required>
                <button type="submit" class="tdgp-btn tdgp-btn-secondary">Activate</button>
            </form>
        </section>
        <?php
    }

    /** Free plugin that couldn't register yet (e.g. the server was unreachable). */
    private function renderRegister(): void
    {
        ?>
        <section class="tdgp-card tdgp-activate">
            <div class="tdgp-activate-head">
                <span class="tdgp-icon-tile" aria-hidden="true"><?php echo $this->icon('refresh'); // static SVG ?></span>
                <h2>Turn on updates</h2>
                <p><?php echo esc_html($this->client->name()); ?> is free. Register this site to receive updates — no key needed.</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php $this->hidden('register'); ?>
                <button type="submit" class="tdgp-btn tdgp-btn-primary tdgp-btn-block"><?php echo $this->icon('check'); // static SVG ?> Register for updates</button>
            </form>
        </section>
        <?php
    }

    private function renderActive(string $status): void
    {
        $license = $this->client->license();
        $d = $license->details();
        $max = $d['max_sites'] === null ? null : (int) $d['max_sites'];
        $used = (int) ($d['sites_used'] ?? 0);
        $expires = $d['expires_at'] ? (int) $d['expires_at'] : null;
        $dateFormat = (string) get_option('date_format');
        ?>
        <?php if ($status !== 'active') : ?>
            <div class="tdgp-alert tdgp-alert-warn" role="alert">
                <?php echo $this->icon('alert'); // static SVG ?>
                <span>This licence is <strong><?php echo esc_html($status); ?></strong>. Updates are paused until it is active again.</span>
            </div>
        <?php endif; ?>

        <section class="tdgp-card">
            <div class="tdgp-card-head">
                <h2>Licence</h2>
                <code class="tdgp-keychip">TDG-••••-••••-••••-<?php echo esc_html(strtoupper((string) $d['key_hint'])); ?></code>
            </div>

            <div class="tdgp-stats">
                <div class="tdgp-stat">
                    <span class="tdgp-stat-label">Plan</span>
                    <span class="tdgp-stat-value"><?php echo esc_html((string) ($d['plan'] ?: 'Custom')); ?></span>
                </div>
                <div class="tdgp-stat">
                    <span class="tdgp-stat-label">Renews / expires</span>
                    <span class="tdgp-stat-value"><?php echo $expires ? esc_html(wp_date($dateFormat, $expires)) : 'Never'; ?></span>
                    <span class="tdgp-stat-note">
                        <?php
                        if (!$expires) {
                            echo 'Lifetime licence';
                        } elseif ($expires > time()) {
                            echo esc_html('in ' . human_time_diff(time(), $expires));
                        } else {
                            echo esc_html(human_time_diff($expires) . ' ago');
                        }
                        ?>
                    </span>
                </div>
                <div class="tdgp-stat">
                    <span class="tdgp-stat-label">Sites</span>
                    <span class="tdgp-stat-value"><?php echo $max === 0 ? esc_html($used . ' · unlimited') : esc_html($used . ' of ' . $max); ?></span>
                    <?php if ($max) : ?>
                        <span class="tdgp-meter" aria-hidden="true"><span style="width:<?php echo esc_attr((string) min(100, max(4, round($used / $max * 100)))); ?>%"></span></span>
                    <?php else : ?>
                        <span class="tdgp-stat-note">Dev sites are always free</span>
                    <?php endif; ?>
                </div>
                <div class="tdgp-stat">
                    <span class="tdgp-stat-label">Last checked</span>
                    <span class="tdgp-stat-value"><?php echo $d['checked_at'] ? esc_html(human_time_diff((int) $d['checked_at']) . ' ago') : '—'; ?></span>
                    <?php if ($d['valid_until']) : ?>
                        <span class="tdgp-stat-note">Works offline until <?php echo esc_html(wp_date($dateFormat, (int) $d['valid_until'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($d['last_error']) : ?>
                <div class="tdgp-alert tdgp-alert-error tdgp-inset" role="alert">
                    <?php echo $this->icon('alert'); // static SVG ?>
                    <span>Last check failed: <?php echo esc_html((string) $d['last_error']); ?></span>
                </div>
            <?php endif; ?>

            <div class="tdgp-card-foot">
                <span class="tdgp-muted"><?php echo $this->icon('globe'); // static SVG ?> <?php echo esc_html(Site::origin()); ?></span>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php $this->hidden('check'); ?>
                    <button type="submit" class="tdgp-btn tdgp-btn-secondary"><?php echo $this->icon('refresh'); // static SVG ?> Check now</button>
                </form>
            </div>
        </section>

        <div class="tdgp-columns">
        <?php $this->updatesCard($status); ?>
        <section class="tdgp-danger">
            <div>
                <p class="tdgp-strong">Deactivate this site</p>
                <p class="tdgp-muted">Frees the seat for another site. Updates stop until you activate again.</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Deactivate this site and free its seat?')">
                <?php $this->hidden('deactivate'); ?>
                <button type="submit" class="tdgp-btn tdgp-btn-danger">Deactivate</button>
            </form>
        </section>
        </div>
        <?php
    }

    private function updatesCard(string $status): void
    {
        $license = $this->client->license();
        $cached = $this->client->updater()->cached();
        $update = is_array($cached) ? ($cached['update'] ?? null) : null;
        $hasUpdate = is_array($update) && version_compare((string) $update['version'], $this->client->version(), '>');
        ?>
        <section class="tdgp-card">
            <div class="tdgp-card-head">
                <h2>Updates</h2>
                <?php if ($hasUpdate) : ?>
                    <span class="tdgp-pill tdgp-pill-info"><span class="tdgp-dot"></span>v<?php echo esc_html((string) $update['version']); ?> available</span>
                <?php elseif ($status === 'active') : ?>
                    <span class="tdgp-pill tdgp-pill-ok"><?php echo $this->icon('check'); // static SVG ?> Up to date</span>
                <?php endif; ?>
            </div>
            <div class="tdgp-row-between">
                <div>
                    <p class="tdgp-strong">Installed version <?php echo esc_html($this->client->version()); ?></p>
                    <p class="tdgp-muted">
                        <?php echo $hasUpdate
                            ? 'Signed and verified before it installs. Install it from the Updates screen, or let auto-updates handle it.'
                            : 'New releases arrive automatically through the WordPress Updates screen.'; ?>
                    </p>
                </div>
                <?php if ($hasUpdate) : ?>
                    <a class="tdgp-btn tdgp-btn-primary" href="<?php echo esc_url(admin_url('update-core.php')); ?>">Update now</a>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tdgp-toggle-row">
                <?php $this->hidden('beta'); ?>
                <label class="tdgp-switch">
                    <input type="checkbox" name="beta" value="1" <?php checked($license->beta()); ?> onchange="this.form.submit()">
                    <span class="tdgp-switch-track" aria-hidden="true"></span>
                    <span>
                        <span class="tdgp-strong">Beta updates</span>
                        <span class="tdgp-muted tdgp-block">Get new versions early. Best left off on production sites.</span>
                    </span>
                </label>
                <noscript><button type="submit" class="tdgp-btn tdgp-btn-secondary">Save</button></noscript>
            </form>
        </section>

        <?php
    }

    /** Free plugins: just the updates card, full width. */
    private function renderUpdates(string $status): void
    {
        $this->updatesCard($status);
    }

    private function statusPill(string $status): void
    {
        $map = [
            'active' => ['ok', 'Active'],
            'expired' => ['muted', 'Expired'],
            'suspended' => ['warn', 'Suspended'],
            'revoked' => ['error', 'Revoked'],
            'inactive' => ['muted', 'Not activated'],
        ];
        [$tone, $label] = $map[$status] ?? ['muted', ucfirst($status)];
        printf('<span class="tdgp-pill tdgp-pill-%s"><span class="tdgp-dot"></span>%s</span>', esc_attr($tone), esc_html($label));
    }

    private function hidden(string $op): void
    {
        printf('<input type="hidden" name="action" value="%s"><input type="hidden" name="op" value="%s">', esc_attr($this->action()), esc_attr($op));
        wp_nonce_field($this->action());
    }

    /** @param array{0:string,1:string} $flash */
    private function flash(array $flash): void
    {
        set_transient($this->flashKey(), $flash, 60);
    }

    private function flashKey(): string
    {
        return 'tdgpush_flash_' . get_current_user_id();
    }

    private function successMessage(string $op): string
    {
        switch ($op) {
            case 'activate':
                return 'Licence activated. Updates are on.';
            case 'register':
                return 'Registered. This site now receives updates.';
            case 'deactivate':
                return 'This site was deactivated and its seat released.';
            case 'check':
                return 'Licence checked.';
            case 'beta':
                return 'Update channel saved.';
            default:
                return 'Done.';
        }
    }

    private function action(): string
    {
        return 'tdgpush_' . str_replace('-', '_', $this->client->slug());
    }

    /** Inline icons (Lucide shapes), so the screen needs no external assets. */
    private function icon(string $name): string
    {
        $paths = [
            'sprout' => '<path d="M12 21V10"/><path d="M12 10c0-3.5 2.5-6 6.5-6 0 4-2.5 6.5-6.5 6Z"/><path d="M12 13c0-2.8-2-4.8-5.5-4.8 0 3.2 2 5 5.5 4.8Z"/><path d="M8 21h8"/>',
            'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
            'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
            'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        ];
        return '<svg class="tdgp-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
    }

    private function styles(): void
    {
        ?>
        <style>
            .tdgp{max-width:1280px!important;--g:#1f7a4d;--g-2:#2e9e67;--g-soft:#e9f6ef;--ink:#1d2327;--mut:#646970;--line:#e3e5e8;--card:#fff;--warn:#9a5b00;--warn-soft:#fff6e5;--err:#b32d2e;--err-soft:#fcf0f1;--info:#1b5fb4;--info-soft:#eaf2fd;margin:0 auto!important;padding-right:20px;color:var(--ink)}
            .tdgp *{box-sizing:border-box}
            .tdgp-sr{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
            .tdgp p{margin:0}
            .tdgp .tdgp-i{width:16px;height:16px;flex:none;vertical-align:-3px}
            .tdgp-hero{display:flex;align-items:center;gap:14px;margin:18px 0 16px}
            .tdgp-mark{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;color:#fff;background:linear-gradient(135deg,var(--g-2),var(--g));box-shadow:0 6px 16px -6px rgba(31,122,77,.55)}
            .tdgp-mark .tdgp-i{width:24px;height:24px}
            .tdgp-hero-text{flex:1;min-width:0}
            .tdgp-title{font-size:20px;font-weight:600;line-height:1.25}
            .tdgp-sub{color:var(--mut);margin-top:2px!important}
            .tdgp-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
            .tdgp-pill .tdgp-i{width:14px;height:14px}
            .tdgp-dot{width:7px;height:7px;border-radius:50%;background:currentColor}
            .tdgp-pill-ok{background:var(--g-soft);color:var(--g)}
            .tdgp-pill-ok .tdgp-dot{box-shadow:0 0 0 3px rgba(46,158,103,.18)}
            .tdgp-pill-warn{background:var(--warn-soft);color:var(--warn)}
            .tdgp-pill-error{background:var(--err-soft);color:var(--err)}
            .tdgp-pill-info{background:var(--info-soft);color:var(--info)}
            .tdgp-pill-muted{background:#f0f0f1;color:var(--mut)}
            .tdgp-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px 24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .tdgp-card h2{font-size:15px;font-weight:600;margin:0}
            .tdgp-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
            .tdgp-keychip{font:12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;background:#f6f7f7;border:1px solid var(--line);border-radius:8px;padding:7px 10px;color:var(--ink)}
            .tdgp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:12px;overflow:hidden}
            .tdgp-stat{background:var(--card);padding:14px 16px;display:flex;flex-direction:column;gap:4px;min-width:0}
            .tdgp-stat-label{font-size:12px;color:var(--mut)}
            .tdgp-stat-value{font-size:15px;font-weight:600}
            .tdgp-stat-note{font-size:12px;color:var(--mut)}
            .tdgp-meter{display:block;height:6px;border-radius:999px;background:#eef0f1;overflow:hidden;margin-top:4px}
            .tdgp-meter span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--g-2),var(--g))}
            .tdgp-card-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:18px}
            .tdgp-muted{color:var(--mut)}
            .tdgp-strong{font-weight:600}
            .tdgp-block{display:block}
            .tdgp-row-between{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
            .tdgp-row-between .tdgp-muted{margin-top:2px!important}
            .tdgp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:36px;padding:0 16px;border-radius:9px;font-size:13px;font-weight:600;line-height:1;cursor:pointer;text-decoration:none;border:1px solid transparent;transition:background .15s,border-color .15s,box-shadow .15s}
            .tdgp-btn:focus-visible{outline:2px solid var(--g-2);outline-offset:2px}
            .tdgp-btn-primary{background:var(--g);color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.08)}
            .tdgp-btn-primary:hover,.tdgp-btn-primary:focus{background:#19663f;color:#fff}
            .tdgp-btn-secondary{background:#fff;border-color:#c7cbd0;color:var(--ink)}
            .tdgp-btn-secondary:hover{border-color:var(--g);color:var(--g)}
            .tdgp-btn-danger{background:#fff;border-color:#e6b9ba;color:var(--err)}
            .tdgp-btn-danger:hover{background:var(--err-soft)}
            .tdgp-btn-block{width:100%;min-height:44px;font-size:14px}
            .tdgp-alert{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:13px}
            .tdgp-alert .tdgp-i{margin-top:1px}
            .tdgp-alert-ok{background:var(--g-soft);color:#155a38}
            .tdgp-alert-error{background:var(--err-soft);color:var(--err)}
            .tdgp-alert-warn{background:var(--warn-soft);color:var(--warn)}
            .tdgp-inset{margin:16px 0 0}
            .tdgp-toggle-row{border-top:1px solid var(--line);margin-top:18px;padding-top:16px}
            .tdgp-switch{display:flex;align-items:flex-start;gap:12px;cursor:pointer}
            .tdgp-switch input{position:absolute;opacity:0;width:1px;height:1px}
            .tdgp-switch-track{flex:none;position:relative;width:36px;height:20px;border-radius:999px;background:#c3c4c7;transition:background .15s;margin-top:1px}
            .tdgp-switch-track::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
            .tdgp-switch input:checked+.tdgp-switch-track{background:var(--g)}
            .tdgp-switch input:checked+.tdgp-switch-track::after{transform:translateX(16px)}
            .tdgp-switch input:focus-visible+.tdgp-switch-track{outline:2px solid var(--g-2);outline-offset:2px}
            .tdgp-danger{display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;gap:16px;border:1px dashed #e6b9ba;border-radius:14px;padding:22px 24px;margin-bottom:16px}
            .tdgp-columns{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:16px;align-items:stretch}
            .tdgp-columns>*{margin-bottom:0}
            .tdgp-columns+*{margin-top:16px}
            .tdgp-danger .tdgp-muted{margin-top:2px!important}
            .tdgp-activate{padding:32px 28px;max-width:620px;margin-left:auto;margin-right:auto}
            .tdgp-activate-head{text-align:center;margin-bottom:22px}
            .tdgp-activate-head h2{font-size:18px;margin-top:12px}
            .tdgp-activate-head p{color:var(--mut);margin-top:6px!important}
            .tdgp-icon-tile{display:inline-grid;place-items:center;width:44px;height:44px;border-radius:12px;background:var(--g-soft);color:var(--g)}
            .tdgp-icon-tile .tdgp-i{width:22px;height:22px}
            .tdgp-activate form,.tdgp-activate .tdgp-hint,.tdgp-activate .tdgp-divider{max-width:420px;margin-left:auto;margin-right:auto}
            .tdgp-hint{font-size:12px;color:var(--mut);margin-top:8px!important}
            .tdgp-center{text-align:center}
            .tdgp-divider{display:flex;align-items:center;gap:12px;color:var(--mut);font-size:12px;margin-top:22px;margin-bottom:14px}
            .tdgp-divider::before,.tdgp-divider::after{content:"";flex:1;height:1px;background:var(--line)}
            .tdgp-keyform{display:flex;gap:8px}
            .tdgp .tdgp-input{flex:1;min-width:0;min-height:36px;border:1px solid #c7cbd0;border-radius:9px;padding:0 12px;font:13px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.02em}
            .tdgp .tdgp-input:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(46,158,103,.2);outline:none}
            .tdgp-foot{color:var(--mut);font-size:12px;margin-top:16px!important}
            .tdgp-foot a{color:var(--g)}
            @media (max-width:1100px){.tdgp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.tdgp-columns{grid-template-columns:1fr}.tdgp-columns>*+*{margin-top:0}}
            @media (max-width:600px){.tdgp-stats{grid-template-columns:1fr}.tdgp-keyform{flex-direction:column}.tdgp-card{padding:18px}.tdgp-activate{padding:24px 18px}}
        </style>
        <?php
    }
}
