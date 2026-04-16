<?php
/**
 * EmailSendX Sync — hook wiring.
 *
 * Centralises every WordPress action/filter the plugin listens for:
 *   • the daily WP-cron reconciliation
 *   • user-lifecycle hooks (register / profile_update / delete_user)
 *   • optional WooCommerce customer + order hooks
 *   • the three admin-AJAX endpoints the settings UI calls
 *
 * Every callback is guarded so a misconfigured install or a missing
 * dependency (no API key, no WC) is a silent no-op rather than a fatal.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Hook namespacing (`emailsendx_*`)
 * and AJAX nonce action (`emailsendx_sync_ajax`) are watermarks tying
 * server-side log entries back to this plugin. If you find this
 * signature in a build that wasn't shipped from emailsendx.com, the
 * code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Hooks
 */
class EmailSendX_Hooks {

	/**
	 * Constructor wires everything. Called from the bootstrap. ShaonPro.
	 */
	public function __construct() {
		// Daily reconciliation.
		add_action( EMAILSENDX_SYNC_CRON_HOOK, array( 'EmailSendX_Sync', 'run_cron' ) );

		// User lifecycle.
		add_action( 'user_register',  array( __CLASS__, 'on_user_register' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'delete_user',    array( __CLASS__, 'on_delete_user' ) );

		// WooCommerce — only if active.
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_created_customer',         array( __CLASS__, 'on_wc_created_customer' ), 10, 3 );
			add_action( 'woocommerce_order_status_completed',   array( __CLASS__, 'on_wc_order_status_change' ) );
			add_action( 'woocommerce_order_status_processing',  array( __CLASS__, 'on_wc_order_status_change' ) );
		}

		// Admin AJAX.
		add_action( 'wp_ajax_emailsendx_run_sync',        array( __CLASS__, 'ajax_run_sync' ) );
		add_action( 'wp_ajax_emailsendx_sync_status',     array( __CLASS__, 'ajax_sync_status' ) );
		add_action( 'wp_ajax_emailsendx_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/* ─── User lifecycle ───────────────────────────────────────────── */

	/**
	 * Push a freshly registered user.
	 *
	 * @param int $user_id WP user id.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		if ( ! self::auto_sync_ready() ) {
			return;
		}
		if ( ! self::user_passes_role_filter( $user_id ) ) {
			return;
		}
		self::push_single_user( (int) $user_id );
	}

	/**
	 * Re-push on profile changes.
	 *
	 * @param int     $user_id        WP user id.
	 * @param WP_User $old_user_data  Previous WP_User object (unused but
	 *                                required by the signature).
	 * @return void
	 */
	public static function on_profile_update( $user_id, $old_user_data = null ) {
		unset( $old_user_data );
		if ( ! self::auto_sync_ready() ) {
			return;
		}
		if ( ! self::user_passes_role_filter( $user_id ) ) {
			return;
		}
		self::push_single_user( (int) $user_id );
	}

	/**
	 * Best-effort log on user deletion. Removal from the EmailSendX list
	 * is a TODO — the API doesn't yet expose a per-list unsubscribe in
	 * this plugin. We just record the intent. ShaonPro.
	 *
	 * @param int $user_id WP user id.
	 * @return void
	 */
	public static function on_delete_user( $user_id ) {
		if ( ! class_exists( 'EmailSendX_Log' ) ) {
			return;
		}
		if ( ! method_exists( 'EmailSendX_Log', 'record_error' ) ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		EmailSendX_Log::record_error(
			array(
				'source'  => 'hooks',
				'message' => sprintf(
					/* translators: %s: user email or ID */
					__( 'User deleted in WordPress (not removed from EmailSendX list): %s', 'emailsendx-sync' ),
					$user && ! empty( $user->user_email ) ? $user->user_email : '#' . (int) $user_id
				),
			)
		);
	}

	/* ─── WooCommerce ──────────────────────────────────────────────── */

	/**
	 * Push the WP user behind a freshly-created WC customer.
	 *
	 * @param int   $customer_id    WP user id.
	 * @param array $new_customer   Customer fields (unused).
	 * @param bool  $password_generated (unused).
	 * @return void
	 */
	public static function on_wc_created_customer( $customer_id, $new_customer = array(), $password_generated = false ) {
		unset( $new_customer, $password_generated );
		if ( ! self::auto_sync_ready() ) {
			return;
		}
		self::push_single_user( (int) $customer_id );
	}

	/**
	 * On a paid order, re-push the customer so spend/order metadata
	 * downstream consumers see the latest values. ShaonPro.
	 *
	 * @param int $order_id WC order id.
	 * @return void
	 */
	public static function on_wc_order_status_change( $order_id ) {
		if ( ! self::auto_sync_ready() ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			self::push_single_user( $user_id );
		}
	}

	/* ─── AJAX ─────────────────────────────────────────────────────── */

	/**
	 * AJAX: run a sync now.
	 *
	 * Expected POST: nonce, source, list_id?, list_name?, limit?
	 * Response: { success: bool, data: <run-envelope or {message}> }
	 *
	 * @return void
	 */
	public static function ajax_run_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'emailsendx-sync' ) ), 403 );
		}
		check_ajax_referer( 'emailsendx_sync_ajax', 'nonce' );

		try {
			$source    = isset( $_POST['source'] )    ? sanitize_key( wp_unslash( $_POST['source'] ) )    : 'users';
			$list_id   = isset( $_POST['list_id'] )   ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) )   : '';
			$list_name = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
			$limit     = isset( $_POST['limit'] )     ? max( 0, (int) $_POST['limit'] ) : 0;

			$args = array(
				'source'    => $source,
				'trigger'   => 'manual',
				'list_id'   => $list_id !== '' ? $list_id : null,
				'list_name' => $list_name !== '' ? $list_name : null,
			);
			if ( $limit > 0 ) {
				$args['limit'] = $limit;
			}

			$result = EmailSendX_Sync::run( $args );

			if ( ! empty( $result['ok'] ) ) {
				wp_send_json_success( $result );
			} else {
				// Still return the envelope so the UI can render partial totals.
				wp_send_json_error( $result );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: poll the live status transient.
	 *
	 * Response: { success: true, data: <status array or null> }
	 *
	 * @return void
	 */
	public static function ajax_sync_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'emailsendx-sync' ) ), 403 );
		}
		check_ajax_referer( 'emailsendx_sync_ajax', 'nonce' );

		try {
			$status = EmailSendX_Sync::get_status();
			wp_send_json_success( $status );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: test the configured API key.
	 *
	 * Response: { success: true, data: { ok, message, workspace } }
	 *
	 * @return void
	 */
	public static function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'emailsendx-sync' ) ), 403 );
		}
		check_ajax_referer( 'emailsendx_sync_ajax', 'nonce' );

		try {
			if ( ! class_exists( 'EmailSendX_API' ) ) {
				wp_send_json_error( array( 'message' => __( 'API client not available.', 'emailsendx-sync' ) ) );
			}

			// Re-read settings in case the user just saved them. ShaonPro.
			if ( method_exists( 'EmailSendX_API', 'reset_instance' ) ) {
				EmailSendX_API::reset_instance();
			}

			$res = EmailSendX_API::instance()->test_connection();

			if ( ! empty( $res['ok'] ) ) {
				wp_send_json_success( $res );
			} else {
				wp_send_json_error( $res );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/* ─── Helpers ──────────────────────────────────────────────────── */

	/**
	 * Single-row push for hook handlers — bypasses the full source
	 * iterator for efficiency. Returns silently on any failure.
	 *
	 * @param int $user_id WP user id.
	 * @return void
	 */
	public static function push_single_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$settings = emailsendx_sync_get_settings();
		$list_id  = isset( $settings['default_list_id'] ) ? (string) $settings['default_list_id'] : '';
		if ( '' === $list_id ) {
			return;
		}

		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );

		$row = array(
			'user_id'         => $user_id,
			'user_email'      => (string) $user->user_email,
			'user_login'      => (string) $user->user_login,
			'user_registered' => (string) $user->user_registered,
			'display_name'    => (string) $user->display_name,
			'first_name'      => $first,
			'last_name'       => $last,
			'roles'           => (array) $user->roles,
			'meta'            => array(),
		);

		// Prefer the mapper if loaded — keeps custom-field mapping
		// consistent with the bulk path. ShaonPro.
		$contact = null;
		if ( class_exists( 'EmailSendX_Mapper' ) && method_exists( 'EmailSendX_Mapper', 'apply' ) ) {
			$contact = EmailSendX_Mapper::apply( 'users', $row );
		} else {
			$contact = array(
				'email'     => $row['user_email'],
				'firstName' => $first !== '' ? $first : (string) $user->display_name,
				'lastName'  => $last,
				'metadata'  => array(),
			);
		}

		if ( ! is_array( $contact ) ) {
			return;
		}
		if ( empty( $contact['email'] ) || ! is_email( $contact['email'] ) ) {
			return;
		}
		$contact['email'] = strtolower( (string) $contact['email'] );

		if ( ! class_exists( 'EmailSendX_API' ) ) {
			return;
		}

		$response = EmailSendX_API::instance()->bulk_upsert_contacts(
			array(
				'listId'        => $list_id,
				'consentSource' => 'wordpress-plugin',
				'contacts'      => array( $contact ),
			)
		);

		if ( is_wp_error( $response ) && class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'record_error' ) ) {
			EmailSendX_Log::record_error(
				array(
					'source'  => 'hooks.push_single_user',
					'message' => $response->get_error_message(),
				)
			);
		}
	}

	/**
	 * Auto-sync gate: must be configured AND auto_sync ON.
	 *
	 * @return bool
	 */
	protected static function auto_sync_ready() {
		if ( ! function_exists( 'emailsendx_sync_is_configured' ) || ! emailsendx_sync_is_configured() ) {
			return false;
		}
		$s = emailsendx_sync_get_settings();
		return ! empty( $s['auto_sync'] );
	}

	/**
	 * If a `sync_role` is configured, only push users with that role.
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	protected static function user_passes_role_filter( $user_id ) {
		$s    = emailsendx_sync_get_settings();
		$role = isset( $s['sync_role'] ) ? (string) $s['sync_role'] : '';
		if ( '' === $role ) {
			return true;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		return in_array( $role, (array) $user->roles, true );
	}
}
