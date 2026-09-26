<?php
/**
 * The "Updates" card on the Settings tab.
 *
 * Updates come from DevGarden Push (see emailsendx_sync_updates() in the
 * main plugin file). This class shows that state and offers three actions
 * — check now, update now, and turn WordPress auto-updates on or off for
 * this plugin — so site admins don't have to hunt through Dashboard →
 * Updates. Rendering reads cached state only; the network is touched only
 * when the admin clicks "Check for updates".
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Update status and actions for the Settings tab.
 */
class EmailSendX_Updates {

	const ACTION_CHECK = 'emailsendx_check_updates';
	const ACTION_AUTO  = 'emailsendx_auto_updates';

	/**
	 * Register the admin-post handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION_CHECK, array( $this, 'check' ) );
		add_action( 'admin_post_' . self::ACTION_AUTO, array( $this, 'toggle_auto' ) );
	}

	/**
	 * The update client, once emailsendx_sync_updates() has created it.
	 *
	 * @return \EmailSendX\Push\Client|null
	 */
	private static function client() {
		return class_exists( '\EmailSendX\Push\Client' ) ? \EmailSendX\Push\Client::get( EMAILSENDX_SYNC_SLUG ) : null;
	}

	/**
	 * @return string
	 */
	public static function check_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CHECK ), self::ACTION_CHECK );
	}

	/**
	 * @param bool $enable Turn auto-updates on (true) or off.
	 * @return string
	 */
	public static function auto_url( $enable ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_AUTO . '&enable=' . ( $enable ? '1' : '0' ) ), self::ACTION_AUTO );
	}

	/**
	 * Everything the card shows, from cached state (no network).
	 *
	 * @return array{registered:bool, installed:string, latest:?string, available:bool, checked_at:?int, error:?string, auto:bool, auto_allowed:bool, update_url:?string}
	 */
	public static function summary() {
		$client    = self::client();
		$installed = EMAILSENDX_SYNC_VERSION;
		$out       = array(
			'registered'   => false,
			'installed'    => $installed,
			'latest'       => null,
			'available'    => false,
			'checked_at'   => null,
			'error'        => null,
			'auto'         => false,
			'auto_allowed' => function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' ),
			'update_url'   => null,
		);
		if ( ! $client ) {
			return $out;
		}

		$details           = $client->license()->details();
		$out['registered'] = $client->license()->is_activated();
		$out['checked_at'] = $details['checked_at'] ? (int) $details['checked_at'] : null;
		$out['error']      = $details['last_error'] ? (string) $details['last_error'] : null;
		$out['auto']       = $client->autoUpdateEnabled();

		$state  = $client->updater()->cached();
		$update = is_array( $state ) && is_array( $state['update'] ?? null ) ? $state['update'] : null;
		if ( $update && version_compare( (string) $update['version'], $installed, '>' ) ) {
			$out['latest']     = (string) $update['version'];
			$out['available']  = true;
			$out['update_url'] = wp_nonce_url(
				self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( EMAILSENDX_SYNC_BASENAME ) ),
				'upgrade-plugin_' . EMAILSENDX_SYNC_BASENAME
			);
		} elseif ( is_array( $state ) ) {
			$out['latest'] = $installed;
		}
		return $out;
	}

	/**
	 * "Check for updates": register if needed, ask the server, refresh WordPress's update list.
	 *
	 * @return void
	 */
	public function check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'emailsendx-sync' ), 403 );
		}
		check_admin_referer( self::ACTION_CHECK );

		$client = self::client();
		$flash  = array(
			'ok'      => false,
			'message' => __( 'Couldn\'t reach the update server. Your host may block outgoing requests — try again in a few minutes.', 'emailsendx-sync' ),
		);
		if ( $client && ( $client->license()->is_activated() || true === $client->license()->register() ) ) {
			$client->updater()->flush();
			$state = $client->updater()->state( true );
			if ( is_array( $state ) ) {
				$update = is_array( $state['update'] ?? null ) ? $state['update'] : null;
				$flash  = $update && version_compare( (string) $update['version'], EMAILSENDX_SYNC_VERSION, '>' )
					/* translators: %s: version number */
					? array( 'ok' => true, 'message' => sprintf( __( 'Version %s is available — update it from the Updates card below.', 'emailsendx-sync' ), $update['version'] ) )
					/* translators: %s: version number */
					: array( 'ok' => true, 'message' => sprintf( __( 'You\'re up to date — %s is the latest version.', 'emailsendx-sync' ), EMAILSENDX_SYNC_VERSION ) );
			}
		}
		set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $flash, 60 );
		wp_safe_redirect( EmailSendX_Admin::get_admin_url( 'settings' ) );
		exit;
	}

	/**
	 * Turn WordPress auto-updates on or off for this plugin (what the Plugins screen's link does).
	 *
	 * @return void
	 */
	public function toggle_auto() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'emailsendx-sync' ), 403 );
		}
		check_admin_referer( self::ACTION_AUTO );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified just above.
		$enable = isset( $_GET['enable'] ) && '1' === $_GET['enable'];
		$list   = (array) get_site_option( 'auto_update_plugins', array() );
		$list   = $enable
			? array_values( array_unique( array_merge( $list, array( EMAILSENDX_SYNC_BASENAME ) ) ) )
			: array_values( array_diff( $list, array( EMAILSENDX_SYNC_BASENAME ) ) );
		update_site_option( 'auto_update_plugins', $list );

		set_transient(
			EMAILSENDX_SYNC_STATUS_TRANS,
			array(
				'ok'      => true,
				'message' => $enable
					? __( 'Automatic updates are on — new versions install themselves.', 'emailsendx-sync' )
					: __( 'Automatic updates are off — new versions appear under Dashboard → Updates.', 'emailsendx-sync' ),
			),
			60
		);
		wp_safe_redirect( EmailSendX_Admin::get_admin_url( 'settings' ) );
		exit;
	}
}
