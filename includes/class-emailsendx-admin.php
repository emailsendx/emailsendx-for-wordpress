<?php
/**
 * EmailSendX Admin shell.
 *
 * Owns the WP admin menu, the tab router and the asset enqueue. Each
 * tab body lives in its own view file under `admin/views/`.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The menu slug, tab slugs and the
 * `EmailSendXSync` JS global are part of the plugin's stable contract
 * — if you find this file in a build that wasn't shipped from
 * emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Admin
 *
 * Top-level admin page + tab routing + asset loader.
 */
class EmailSendX_Admin {

	/**
	 * Top-level menu slug. Reused everywhere we build a tab URL.
	 */
	const MENU_SLUG = 'emailsendx-sync';

	/**
	 * Capability required to view the plugin's admin pages.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Cached `$hook` returned by `add_menu_page()` so `enqueue_assets()`
	 * can compare against it instead of hard-coding strings.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Wire the WP hooks. ShaonPro.
	 */
	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * Icon is a base64 SVG using EmailSendX brand blues (logo only — the
	 * menu label keeps WordPress' native colours). ShaonPro.
	 */
	public function register_menu() {
		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 250 250">'
			. '<defs><linearGradient id="esx-menu-mark" x1="12%" y1="8%" x2="92%" y2="96%">'
			. '<stop offset="0%" stop-color="#277AFF"/><stop offset="100%" stop-color="#2563EB"/>'
			. '</linearGradient></defs>'
			. '<path fill="url(#esx-menu-mark)" d="M129.067 53.9333C131.283 51.7168 134.288 50.4701 137.42 50.4673C140.551 50.4645 143.554 51.706 145.766 53.9185L195.821 103.973C198.033 106.186 199.275 109.188 199.272 112.32C199.269 115.451 198.022 118.456 195.806 120.673L128.949 187.53C126.732 189.746 123.728 190.993 120.596 190.996C117.464 190.998 114.462 189.757 112.249 187.544L108.078 183.373L116.435 175.016L120.606 179.187L187.464 112.33L143.345 68.2113L143.294 126.117C143.291 128.465 142.356 130.718 140.694 132.38C139.032 134.042 136.779 134.977 134.431 134.98L76.4501 135.031L78.8945 137.475L70.5373 145.832L62.1949 137.49C59.9824 135.277 58.7409 132.275 58.7437 129.143C58.7465 126.011 59.9932 123.007 62.2096 120.79L129.067 53.9333ZM108.108 149.974C109.174 148.91 110.605 148.293 112.11 148.248C113.614 148.204 115.077 148.735 116.201 149.734C117.325 150.733 118.024 152.124 118.156 153.623C118.287 155.122 117.842 156.615 116.91 157.798L116.45 158.316L95.5572 179.209C94.491 180.273 93.0595 180.89 91.5552 180.935C90.0509 180.98 88.5874 180.448 87.4637 179.449C86.3399 178.45 85.6408 177.059 85.5091 175.561C85.3774 174.062 85.8231 172.568 86.7551 171.385L87.2148 170.867L108.108 149.974ZM91.4154 141.639C92.5237 140.531 94.026 139.907 95.5918 139.906C97.1577 139.905 98.6589 140.525 99.7652 141.632C100.871 142.738 101.492 144.239 101.491 145.805C101.489 147.371 100.866 148.873 99.7578 149.981L87.2221 162.517C86.1139 163.625 84.6116 164.249 83.0457 164.25C81.4798 164.251 79.9786 163.631 78.8724 162.524C77.7661 161.418 77.1454 159.917 77.1468 158.351C77.1481 156.785 77.7715 155.283 78.8797 154.175L91.4154 141.639Z"/>'
			. '</svg>';
		$icon_data = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );

		$this->page_hook = add_menu_page(
			__( 'EmailSendX for WordPress', 'emailsendx-sync' ),
			__( 'EmailSendX', 'emailsendx-sync' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			$icon_data,
			58
		);
	}

	/**
	 * Render the admin page shell: header + tab nav + the active view.
	 * ShaonPro.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'emailsendx-sync' ) );
		}

		$tabs    = self::get_tabs();
		$current = self::get_current_tab();

		self::evaluate_preconditions( $current );

		$settings   = emailsendx_sync_get_settings();
		$workspace  = '';
		if ( ! empty( $settings['api_base'] ) ) {
			$host      = wp_parse_url( $settings['api_base'], PHP_URL_HOST );
			$workspace = $host ? $host : $settings['api_base'];
		}

		// Status pill (set by admin-post handlers).
		$status = get_transient( EMAILSENDX_SYNC_STATUS_TRANS );

		?>
		<div class="wrap esx-wrap">
			<div class="esx-header">
				<div class="esx-header-brand">
					<span class="esx-header-mark" aria-hidden="true">
						<svg viewBox="0 0 250 250" xmlns="http://www.w3.org/2000/svg" role="img" focusable="false">
							<rect width="250" height="250" rx="40" fill="#277AFF"/>
							<path d="M129.067 53.9333C131.283 51.7168 134.288 50.4701 137.42 50.4673C140.551 50.4645 143.554 51.706 145.766 53.9185L195.821 103.973C198.033 106.186 199.275 109.188 199.272 112.32C199.269 115.451 198.022 118.456 195.806 120.673L128.949 187.53C126.732 189.746 123.728 190.993 120.596 190.996C117.464 190.998 114.462 189.757 112.249 187.544L108.078 183.373L116.435 175.016L120.606 179.187L187.464 112.33L143.345 68.2113L143.294 126.117C143.291 128.465 142.356 130.718 140.694 132.38C139.032 134.042 136.779 134.977 134.431 134.98L76.4501 135.031L78.8945 137.475L70.5373 145.832L62.1949 137.49C59.9824 135.277 58.7409 132.275 58.7437 129.143C58.7465 126.011 59.9932 123.007 62.2096 120.79L129.067 53.9333ZM108.108 149.974C109.174 148.91 110.605 148.293 112.11 148.248C113.614 148.204 115.077 148.735 116.201 149.734C117.325 150.733 118.024 152.124 118.156 153.623C118.287 155.122 117.842 156.615 116.91 157.798L116.45 158.316L95.5572 179.209C94.491 180.273 93.0595 180.89 91.5552 180.935C90.0509 180.98 88.5874 180.448 87.4637 179.449C86.3399 178.45 85.6408 177.059 85.5091 175.561C85.3774 174.062 85.8231 172.568 86.7551 171.385L87.2148 170.867L108.108 149.974ZM91.4154 141.639C92.5237 140.531 94.026 139.907 95.5918 139.906C97.1577 139.905 98.6589 140.525 99.7652 141.632C100.871 142.738 101.492 144.239 101.491 145.805C101.489 147.371 100.866 148.873 99.7578 149.981L87.2221 162.517C86.1139 163.625 84.6116 164.249 83.0457 164.25C81.4798 164.251 79.9786 163.631 78.8724 162.524C77.7661 161.418 77.1454 159.917 77.1468 158.351C77.1481 156.785 77.7715 155.283 78.8797 154.175L91.4154 141.639Z" fill="#EFF6FF"/>
						</svg>
					</span>
					<div class="esx-header-text">
						<h1 class="esx-header-title"><?php echo esc_html__( 'EmailSendX for WordPress', 'emailsendx-sync' ); ?></h1>
						<p class="esx-header-subtitle">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: plugin version */
									__( 'EmailSendX · v%s', 'emailsendx-sync' ),
									EMAILSENDX_SYNC_VERSION
								)
							);
							?>
						</p>
					</div>
				</div>
				<div class="esx-header-meta">
					<?php if ( emailsendx_sync_is_configured() ) : ?>
						<span class="esx-header-status esx-header-status-ok" title="<?php echo esc_attr__( 'API key configured', 'emailsendx-sync' ); ?>">
							<span class="esx-header-status-dot" aria-hidden="true"></span>
							<?php echo esc_html__( 'Connected', 'emailsendx-sync' ); ?>
						</span>
					<?php else : ?>
						<span class="esx-header-status esx-header-status-warn" title="<?php echo esc_attr__( 'Not connected yet', 'emailsendx-sync' ); ?>">
							<span class="esx-header-status-dot" aria-hidden="true"></span>
							<?php echo esc_html__( 'Not connected', 'emailsendx-sync' ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $workspace ) : ?>
						<div class="esx-header-workspace">
							<span class="esx-header-workspace-label"><?php echo esc_html__( 'Workspace', 'emailsendx-sync' ); ?></span>
							<span class="esx-header-workspace-name"><?php echo esc_html( $workspace ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( is_array( $status ) && isset( $status['message'] ) ) : ?>
				<div class="esx-pill <?php echo ! empty( $status['ok'] ) ? 'esx-pill-ok' : 'esx-pill-error'; ?> esx-pill-flash">
					<?php echo esc_html( $status['message'] ); ?>
				</div>
				<?php delete_transient( EMAILSENDX_SYNC_STATUS_TRANS ); ?>
			<?php endif; ?>

			<nav class="esx-tabs nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'EmailSendX Sync sections', 'emailsendx-sync' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::get_admin_url( $slug ) ); ?>"
						class="nav-tab esx-tab <?php echo $current === $slug ? 'nav-tab-active esx-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="esx-body">
				<?php
				// ShaonPro — route to the requested tab view.
				$view_file = EMAILSENDX_SYNC_PATH . 'admin/views/' . $current . '.php';
				if ( file_exists( $view_file ) ) {
					require $view_file;
				} else {
					echo '<div class="esx-card"><p>' .
						esc_html__( 'View not found.', 'emailsendx-sync' ) .
						'</p></div>';
				}
				?>
			</div>

			<footer class="esx-footer">
				<span class="esx-footer-mark" aria-hidden="true">
					<svg viewBox="0 0 250 250" xmlns="http://www.w3.org/2000/svg" focusable="false">
						<rect width="250" height="250" rx="40" fill="#277AFF"/>
						<path d="M129.067 53.9333C131.283 51.7168 134.288 50.4701 137.42 50.4673C140.551 50.4645 143.554 51.706 145.766 53.9185L195.821 103.973C198.033 106.186 199.275 109.188 199.272 112.32C199.269 115.451 198.022 118.456 195.806 120.673L128.949 187.53C126.732 189.746 123.728 190.993 120.596 190.996C117.464 190.998 114.462 189.757 112.249 187.544L108.078 183.373L116.435 175.016L120.606 179.187L187.464 112.33L143.345 68.2113L143.294 126.117C143.291 128.465 142.356 130.718 140.694 132.38C139.032 134.042 136.779 134.977 134.431 134.98L76.4501 135.031L78.8945 137.475L70.5373 145.832L62.1949 137.49C59.9824 135.277 58.7409 132.275 58.7437 129.143C58.7465 126.011 59.9932 123.007 62.2096 120.79L129.067 53.9333ZM108.108 149.974C109.174 148.91 110.605 148.293 112.11 148.248C113.614 148.204 115.077 148.735 116.201 149.734C117.325 150.733 118.024 152.124 118.156 153.623C118.287 155.122 117.842 156.615 116.91 157.798L116.45 158.316L95.5572 179.209C94.491 180.273 93.0595 180.89 91.5552 180.935C90.0509 180.98 88.5874 180.448 87.4637 179.449C86.3399 178.45 85.6408 177.059 85.5091 175.561C85.3774 174.062 85.8231 172.568 86.7551 171.385L87.2148 170.867L108.108 149.974ZM91.4154 141.639C92.5237 140.531 94.026 139.907 95.5918 139.906C97.1577 139.905 98.6589 140.525 99.7652 141.632C100.871 142.738 101.492 144.239 101.491 145.805C101.489 147.371 100.866 148.873 99.7578 149.981L87.2221 162.517C86.1139 163.625 84.6116 164.249 83.0457 164.25C81.4798 164.251 79.9786 163.631 78.8724 162.524C77.7661 161.418 77.1454 159.917 77.1468 158.351C77.1481 156.785 77.7715 155.283 78.8797 154.175L91.4154 141.639Z" fill="#EFF6FF"/>
					</svg>
				</span>
				<div class="esx-footer-text">
					<a href="https://emailsendx.com" target="_blank" rel="noopener noreferrer" class="esx-footer-link">EmailSendX</a>
					<span class="esx-footer-sep">·</span>
					<span class="esx-footer-version">v<?php echo esc_html( EMAILSENDX_SYNC_VERSION ); ?></span>
					<span class="esx-footer-sep">·</span>
					<a href="https://emailsendx.com/docs/" target="_blank" rel="noopener noreferrer" class="esx-footer-link"><?php echo esc_html__( 'Docs', 'emailsendx-sync' ); ?></a>
					<span class="esx-footer-sep">·</span>
					<a href="https://emailsendx.com/support" target="_blank" rel="noopener noreferrer" class="esx-footer-link"><?php echo esc_html__( 'Support', 'emailsendx-sync' ); ?></a>
				</div>
			</footer>
		</div>
		<?php
	}

	/**
	 * Tab slug → label map. Order = display order.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs() {
		return array(
			'sync'     => __( 'Sync', 'emailsendx-sync' ),
			'mapping'  => __( 'Field Mapping', 'emailsendx-sync' ),
			'log'      => __( 'Log', 'emailsendx-sync' ),
			'settings' => __( 'Settings', 'emailsendx-sync' ),
		);
	}

	/**
	 * Resolve the active tab from the query string with a sane default.
	 * If the plugin isn't configured yet, default to settings so first-
	 * run users land on the credentials form. ShaonPro.
	 *
	 * @return string
	 */
	public static function get_current_tab() {
		$tabs = self::get_tabs();
		$req  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $req && isset( $tabs[ $req ] ) ) {
			return $req;
		}

		return emailsendx_sync_is_configured() ? 'sync' : 'settings';
	}

	/**
	 * Convenience builder for tab URLs. Used by views + the Settings
	 * admin-post redirect.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function get_admin_url( $tab = 'sync' ) {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Run the page-render precondition checks and push/clear notices.
	 * ShaonPro.
	 *
	 * @param string $current_tab The active tab slug.
	 * @return void
	 */
	protected static function evaluate_preconditions( $current_tab ) {
		if ( ! class_exists( 'EmailSendX_Notices' ) ) {
			return;
		}

		$configured   = function_exists( 'emailsendx_sync_is_configured' ) && emailsendx_sync_is_configured();
		$settings     = function_exists( 'emailsendx_sync_get_settings' ) ? emailsendx_sync_get_settings() : array();
		$has_list     = ! empty( $settings['default_list_id'] );
		$auto_sync    = ! empty( $settings['auto_sync'] );
		$roles        = function_exists( 'emailsendx_sync_get_roles' ) ? emailsendx_sync_get_roles() : array();
		$settings_url = self::get_admin_url( 'settings' );

		// 1. Not connected.
		if ( ! $configured && 'settings' !== $current_tab ) {
			EmailSendX_Notices::add(
				'not_connected',
				'warning',
				__( 'EmailSendX is not connected yet. Add your API key in Settings to start syncing.', 'emailsendx-sync' ),
				array(
					'ttl'         => 0,
					'dismissible' => false,
					'actions'     => array( array( 'label' => __( 'Go to Settings', 'emailsendx-sync' ), 'url' => $settings_url ) ),
				)
			);
		} else {
			EmailSendX_Notices::clear( 'not_connected' );
		}

		// 2. Missing default list when on sync/mapping.
		if ( $configured && ! $has_list && in_array( $current_tab, array( 'sync', 'mapping' ), true ) ) {
			EmailSendX_Notices::add(
				'list_missing',
				'warning',
				__( 'No default EmailSendX list selected. Pick one in Settings before syncing.', 'emailsendx-sync' ),
				array(
					'ttl'     => 0,
					'actions' => array( array( 'label' => __( 'Pick a list', 'emailsendx-sync' ), 'url' => $settings_url ) ),
				)
			);
		} else {
			EmailSendX_Notices::clear( 'list_missing' );
		}

		// 3. Auto-sync on but no roles selected → pushing everyone.
		if ( $auto_sync && empty( $roles ) ) {
			EmailSendX_Notices::add(
				'no_roles_selected',
				'info',
				__( 'Auto-sync is pushing all user roles — pick specific roles in Settings if that is not what you want.', 'emailsendx-sync' ),
				array(
					'ttl'     => 86400,
					'actions' => array( array( 'label' => __( 'Choose roles', 'emailsendx-sync' ), 'url' => $settings_url ) ),
				)
			);
		} else {
			EmailSendX_Notices::clear( 'no_roles_selected' );
		}
	}

	/**
	 * Sidebar menu styling on every `wp-admin` screen (small footprint).
	 * ShaonPro.
	 */
	public function enqueue_menu_assets() {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'emailsendx-sync-admin-menu',
			EMAILSENDX_SYNC_URL . 'assets/css/admin-menu.css',
			array(),
			EMAILSENDX_SYNC_VERSION
		);
	}

	/**
	 * Enqueue the plugin's CSS + JS — but only on its own admin page so
	 * we don't pollute the global WP admin.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// `add_menu_page()` returns the hook suffix; compare to that.
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'emailsendx-sync-admin',
			EMAILSENDX_SYNC_URL . 'assets/css/admin.css',
			array(),
			EMAILSENDX_SYNC_VERSION
		);

		wp_enqueue_script(
			'emailsendx-sync-admin',
			EMAILSENDX_SYNC_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			EMAILSENDX_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'emailsendx-sync-admin',
			'EmailSendXSync',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'restBase' => esc_url_raw( rest_url( 'emailsendx/v1/' ) ),
				'nonce'    => wp_create_nonce( 'emailsendx_sync_ajax' ),
				'tab'      => self::get_current_tab(),
				'build'    => SHAONPRO_BUILD,
				'i18n'     => array(
					'syncing'           => __( 'Syncing…', 'emailsendx-sync' ),
					'success'           => __( 'Sync complete.', 'emailsendx-sync' ),
					'failed'            => __( 'Sync failed.', 'emailsendx-sync' ),
					'confirmRun'        => __( 'Run a full sync now?', 'emailsendx-sync' ),
					'batchProgress'     => __( 'Syncing batch %1$d of %2$d…', 'emailsendx-sync' ),
					'contactsPushed'    => __( '%d contacts pushed.', 'emailsendx-sync' ),
					'networkError'      => __( 'Network error. Please retry.', 'emailsendx-sync' ),
					'listPlaceholder'   => __( '— Select a list —', 'emailsendx-sync' ),
					'listPickPlaceholder' => __( '— Pick a list —', 'emailsendx-sync' ),
				),
			)
		);
	}
}
