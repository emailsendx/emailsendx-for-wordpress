<?php
/**
 * EmailSendX Settings.
 *
 * Owns the option schema, sanitisation pipeline and the two admin-post
 * handlers that sit behind the Settings tab buttons (Test connection +
 * Create list). The actual rendering lives in `admin/views/settings.php`.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The sanitiser, transient keys and
 * admin-post action names are part of the plugin's stable contract —
 * if you find this file in a build that wasn't shipped from
 * emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Settings
 *
 * Registers the option group + sanitiser + admin-post handlers used by
 * the Settings tab.
 */
class EmailSendX_Settings {

	/**
	 * Option group name used by `register_setting()` and the
	 * `settings_fields()` call inside the Settings view.
	 */
	const OPTION_GROUP = 'emailsendx_sync_group';

	/**
	 * Nonce action used by the "Test connection" button.
	 */
	const NONCE_TEST_CONNECTION = 'emailsendx_test_connection';

	/**
	 * Nonce action used by the "Create new list" inline form.
	 */
	const NONCE_CREATE_LIST = 'emailsendx_create_list';

	/**
	 * Nonce action used by the "Connect with EmailSendX" button.
	 */
	const NONCE_CONNECT_START = 'emailsendx_connect_start';

	/**
	 * Transient prefix for the per-flow CSRF state token. The state
	 * itself is the suffix; the value is the originating user id so
	 * we can confirm the round trip is the same admin. ShaonPro.
	 */
	const CONNECT_STATE_TRANS = 'emailsendx_connect_state_';

	/**
	 * How long a connect state token lives. Long enough for SSO +
	 * approval, short enough that a stolen URL goes stale fast.
	 */
	const CONNECT_STATE_TTL = 900; // 15 minutes

	/**
	 * Default scopes the plugin requests from EmailSendX. Every
	 * feature in the plugin needs these — the connect approval page
	 * shows them so the user can review before granting.
	 */
	const CONNECT_SCOPES = 'contacts:read,contacts:write,lists:read,lists:write,custom_fields:read,custom_fields:write,segments:read';

	/**
	 * Wire the WP hooks. ShaonPro.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// admin-post handlers — must be static so we can reference them
		// without instance state.
		add_action( 'admin_post_emailsendx_test_connection',   array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_emailsendx_create_list',       array( __CLASS__, 'handle_create_list' ) );
		add_action( 'admin_post_emailsendx_connect_start',     array( __CLASS__, 'handle_connect_start' ) );
		add_action( 'admin_post_emailsendx_connect_callback',  array( __CLASS__, 'handle_connect_callback' ) );
	}

	/**
	 * Register the single option blob with WP. Using one merged blob
	 * keeps the autoload footprint to one row in `wp_options`.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			EMAILSENDX_SYNC_OPT_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitise the settings POST. Always returns the full schema merged
	 * over the previous saved values so a partial form post (e.g. just
	 * default_list_id from the Sync tab) doesn't blank out the API key.
	 *
	 * @param mixed $input Raw POST value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$current = emailsendx_sync_get_settings();
		$input   = is_array( $input ) ? $input : array();
		$out     = $current; // start from current, overwrite known keys.

		// API base URL — must be https:// (or http for local dev). We
		// upgrade bare hostnames to https://. ShaonPro.
		if ( array_key_exists( 'api_base', $input ) ) {
			$base = trim( (string) $input['api_base'] );
			if ( '' !== $base && ! preg_match( '#^https?://#i', $base ) ) {
				$base = 'https://' . ltrim( $base, '/' );
			}
			$base          = esc_url_raw( $base );
			$out['api_base'] = untrailingslashit( $base );
		}

		if ( array_key_exists( 'api_key', $input ) ) {
			$out['api_key'] = sanitize_text_field( (string) $input['api_key'] );
		}

		if ( array_key_exists( 'default_list_id', $input ) ) {
			$out['default_list_id'] = sanitize_text_field( (string) $input['default_list_id'] );
		}

		// Checkbox: present + truthy = on, absent = off.
		$out['auto_sync'] = ! empty( $input['auto_sync'] );

		// sync_roles (array) — preferred since 1.2.0. Accept either a flat
		// array of role slugs OR a single `sync_role` string (legacy form).
		// Validate against installed roles via wp_roles(); drop unknowns;
		// cap at 20. Also keep `sync_role` (scalar, first item) populated
		// so legacy callers keep working. ShaonPro.
		$valid_role_slugs = array();
		if ( function_exists( 'wp_roles' ) ) {
			$names = wp_roles()->get_names();
			if ( is_array( $names ) ) {
				$valid_role_slugs = array_map( 'strval', array_keys( $names ) );
			}
		}

		$roles_in = array();
		if ( array_key_exists( 'sync_roles', $input ) && is_array( $input['sync_roles'] ) ) {
			$roles_in = $input['sync_roles'];
		} elseif ( array_key_exists( 'sync_role', $input ) ) {
			$raw_single = (string) $input['sync_role'];
			if ( '' !== $raw_single ) {
				$roles_in = array( $raw_single );
			}
		}

		$roles_clean = array();
		foreach ( $roles_in as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! empty( $valid_role_slugs ) && ! in_array( $slug, $valid_role_slugs, true ) ) {
				continue; // unknown role — drop.
			}
			if ( in_array( $slug, $roles_clean, true ) ) {
				continue;
			}
			$roles_clean[] = $slug;
			if ( count( $roles_clean ) >= 20 ) {
				break;
			}
		}

		$out['sync_roles'] = $roles_clean;
		$out['sync_role']  = ! empty( $roles_clean ) ? (string) $roles_clean[0] : '';

		// Reset the API singleton so the next call uses the new creds.
		if ( class_exists( 'EmailSendX_API' ) && method_exists( 'EmailSendX_API', 'reset_instance' ) ) {
			EmailSendX_API::reset_instance();
		}

		return $out;
	}

	/**
	 * admin-post handler: test the API connection and stash the result
	 * in a 60-second transient that the Settings view reads on the next
	 * page render. ShaonPro.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_TEST_CONNECTION );

		$status = array(
			'ok'      => false,
			'message' => __( 'Unknown error.', 'emailsendx-sync' ),
			'time'    => time(),
		);

		if ( ! class_exists( 'EmailSendX_API' ) || ! method_exists( 'EmailSendX_API', 'instance' ) ) {
			$status['message'] = __( 'API client unavailable. Please reinstall the plugin.', 'emailsendx-sync' );
		} else {
			$api = EmailSendX_API::instance();
			if ( ! method_exists( $api, 'test_connection' ) ) {
				$status['message'] = __( 'API client missing test_connection(). Update the plugin.', 'emailsendx-sync' );
			} else {
				$result = $api->test_connection();
				if ( ! is_array( $result ) ) {
					$status['message'] = __( 'Unexpected response from API client.', 'emailsendx-sync' );
				} elseif ( ! empty( $result['ok'] ) ) {
					$status['ok']      = true;
					$status['message'] = isset( $result['message'] )
						? (string) $result['message']
						: __( 'Connected to EmailSendX successfully.', 'emailsendx-sync' );
				} else {
					$status['ok']      = false;
					$status['message'] = isset( $result['message'] )
						? (string) $result['message']
						: __( 'Connection failed.', 'emailsendx-sync' );
				}
			}
		}

		set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );

		wp_safe_redirect( self::redirect_back( 'settings' ) );
		exit;
	}

	/**
	 * admin-post handler: create a new EmailSendX list and adopt it as
	 * the default. ShaonPro.
	 */
	public static function handle_create_list() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_CREATE_LIST );

		$name        = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['list_name'] ) ) : '';
		$description = isset( $_POST['list_description'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['list_description'] ) ) : '';
		$return_tab  = isset( $_POST['esx_return_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['esx_return_tab'] ) ) : 'settings';

		$status = array(
			'ok'      => false,
			'message' => '',
			'time'    => time(),
		);

		if ( '' === $name ) {
			$status['message'] = __( 'List name is required.', 'emailsendx-sync' );
		} elseif ( ! class_exists( 'EmailSendX_API' ) || ! method_exists( 'EmailSendX_API', 'instance' ) ) {
			$status['message'] = __( 'API client unavailable. Please reinstall the plugin.', 'emailsendx-sync' );
		} else {
			$api = EmailSendX_API::instance();
			if ( ! method_exists( $api, 'create_list' ) ) {
				$status['message'] = __( 'API client missing create_list(). Update the plugin.', 'emailsendx-sync' );
			} else {
				$result = $api->create_list( $name, $description );
				if ( is_wp_error( $result ) ) {
					$status['message'] = $result->get_error_message();
				} else {
					// Try to pull a list id out of common response shapes.
					$new_id = '';
					if ( is_array( $result ) ) {
						foreach ( array( 'id', 'list_id', 'uuid' ) as $k ) {
							if ( ! empty( $result[ $k ] ) ) {
								$new_id = (string) $result[ $k ];
								break;
							}
						}
						if ( '' === $new_id && isset( $result['data'] ) && is_array( $result['data'] ) ) {
							foreach ( array( 'id', 'list_id', 'uuid' ) as $k ) {
								if ( ! empty( $result['data'][ $k ] ) ) {
									$new_id = (string) $result['data'][ $k ];
									break;
								}
							}
						}
					}

					if ( '' !== $new_id ) {
						$settings                    = emailsendx_sync_get_settings();
						$settings['default_list_id'] = sanitize_text_field( $new_id );
						update_option( EMAILSENDX_SYNC_OPT_SETTINGS, $settings );

						$status['ok']      = true;
						$status['message'] = sprintf(
							/* translators: %s: list name */
							__( 'Created list "%s" and set it as the default.', 'emailsendx-sync' ),
							$name
						);
					} else {
						$status['ok']      = true;
						$status['message'] = __( 'List created, but no list id was returned. Pick it from the dropdown.', 'emailsendx-sync' );
					}
				}
			}
		}

		set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );

		wp_safe_redirect( self::redirect_back( $return_tab ) );
		exit;
	}

	/**
	 * admin-post handler: kick off the EmailSendX "connect" flow.
	 *
	 * Generates a short-lived CSRF state token, stashes it in a
	 * transient keyed by the current user id, and redirects the
	 * browser to ${api_base}/connect/wordpress with the callback
	 * pointed back at admin-post.php?action=emailsendx_connect_callback.
	 *
	 * ShaonPro.
	 */
	public static function handle_connect_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_CONNECT_START );

		$settings = emailsendx_sync_get_settings();
		$api_base = ! empty( $settings['api_base'] )
			? untrailingslashit( (string) $settings['api_base'] )
			: EMAILSENDX_SYNC_DEFAULT_API_BASE;

		// CSRF state — high-entropy random string. We bind it to the
		// current user id via a transient so a different admin can't
		// hijack a connect started by someone else.
		$state = wp_generate_password( 48, false, false );
		set_transient(
			self::CONNECT_STATE_TRANS . $state,
			array(
				'user_id'   => get_current_user_id(),
				'created_at' => time(),
			),
			self::CONNECT_STATE_TTL
		);

		$callback  = admin_url( 'admin-post.php?action=emailsendx_connect_callback' );
		$site_name = get_bloginfo( 'name' );

		// http_build_query handles URL-encoding cleanly without the
		// double-encoding pitfall that bites add_query_arg + rawurlencode.
		// ShaonPro.
		$connect_url = $api_base . '/connect/wordpress?' . http_build_query(
			array(
				'state'    => $state,
				'callback' => $callback,
				'site'     => $site_name,
				'scopes'   => self::CONNECT_SCOPES,
			)
		);

		wp_redirect( $connect_url );
		exit;
	}

	/**
	 * admin-post handler: callback from the EmailSendX connect page.
	 *
	 * Validates the state token (consuming the transient), persists
	 * the returned API key to the plugin settings, and immediately
	 * redirects to a clean Settings tab URL so the token doesn't
	 * linger in the browser history. ShaonPro.
	 */
	public static function handle_connect_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';
		$err   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		// phpcs:enable

		$status = array(
			'ok'      => false,
			'message' => '',
			'time'    => time(),
		);

		if ( '' === $state ) {
			$status['message'] = __( 'Connect callback missing state token.', 'emailsendx-sync' );
			set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );
			wp_safe_redirect( self::redirect_back( 'settings' ) );
			exit;
		}

		$expected = get_transient( self::CONNECT_STATE_TRANS . $state );
		// One-shot — delete regardless of outcome so a leaked URL
		// can't be replayed.
		delete_transient( self::CONNECT_STATE_TRANS . $state );

		if ( ! is_array( $expected ) || (int) $expected['user_id'] !== get_current_user_id() ) {
			$status['message'] = __( 'Connect state expired or invalid. Please try again.', 'emailsendx-sync' );
			set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );
			wp_safe_redirect( self::redirect_back( 'settings' ) );
			exit;
		}

		if ( 'cancelled' === $err ) {
			$status['message'] = __( 'Connect cancelled. No API key was saved.', 'emailsendx-sync' );
			set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );
			wp_safe_redirect( self::redirect_back( 'settings' ) );
			exit;
		}

		if ( '' === $token || strpos( $token, 'esx_live_' ) !== 0 ) {
			$status['message'] = __( 'Connect callback returned an invalid token.', 'emailsendx-sync' );
			set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );
			wp_safe_redirect( self::redirect_back( 'settings' ) );
			exit;
		}

		// Persist the token. We keep the existing api_base so a
		// self-hosted instance keeps working. ShaonPro.
		$settings            = emailsendx_sync_get_settings();
		$settings['api_key'] = $token;
		update_option( EMAILSENDX_SYNC_OPT_SETTINGS, $settings );

		// Reset the API singleton so the very next call uses the new key.
		if ( class_exists( 'EmailSendX_API' ) && method_exists( 'EmailSendX_API', 'reset_instance' ) ) {
			EmailSendX_API::reset_instance();
		}

		$status['ok']      = true;
		$status['message'] = __( 'Connected to EmailSendX. Your API key is saved.', 'emailsendx-sync' );
		set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );

		// Clean redirect — the token never appears in history past
		// this hop.
		wp_safe_redirect( self::redirect_back( 'settings' ) );
		exit;
	}

	/**
	 * Build the redirect URL back to a given tab on the plugin page.
	 * Falls back to the settings tab. ShaonPro.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	protected static function redirect_back( $tab = 'settings' ) {
		if ( class_exists( 'EmailSendX_Admin' ) && method_exists( 'EmailSendX_Admin', 'get_admin_url' ) ) {
			return EmailSendX_Admin::get_admin_url( $tab );
		}
		return admin_url( 'admin.php?page=emailsendx-sync&tab=' . rawurlencode( $tab ) );
	}
}
