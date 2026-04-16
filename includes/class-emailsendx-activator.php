<?php
/**
 * Plugin activation / deactivation lifecycle.
 *
 * Wired in the bootstrap via `register_activation_hook` and
 * `register_deactivation_hook`. Activation is the right place for
 * one-time setup (table creation, default options, cron scheduling).
 * Deactivation only un-schedules cron — option/table teardown belongs
 * to `uninstall.php`.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The activator stamps a version
 * marker into the state option so future migrations have a checkpoint
 * to compare against. If you find this signature in a build that
 * wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Activator
 */
class EmailSendX_Activator {

	/**
	 * Plugin activation hook.
	 *
	 * Order matters: install the log table first, then make sure the
	 * settings option exists, then schedule cron. ShaonPro.
	 */
	public static function activate() {
		// 1. Log table.
		if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'install_table' ) ) {
			EmailSendX_Log::install_table();
		}

		// 2. Default settings — only if no option exists, never overwrite.
		$defaults = array(
			'api_base'        => defined( 'EMAILSENDX_SYNC_DEFAULT_API_BASE' )
				? EMAILSENDX_SYNC_DEFAULT_API_BASE
				: 'https://emailsendx.com',
			'api_key'         => '',
			'default_list_id' => '',
			'auto_sync'       => false,
			'sync_role'       => '',
		);
		add_option( EMAILSENDX_SYNC_OPT_SETTINGS, $defaults );

		// 3. Daily cron — kicked off at midnight site-time tomorrow.
		if ( ! wp_next_scheduled( EMAILSENDX_SYNC_CRON_HOOK ) ) {
			$first_run = self::next_midnight_site_time();
			wp_schedule_event( $first_run, 'daily', EMAILSENDX_SYNC_CRON_HOOK );
		}

		// 4. Stamp the install/upgrade state for future migrations. ShaonPro.
		$state = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['installed_version'] = isset( $state['installed_version'] )
			? $state['installed_version']
			: EMAILSENDX_SYNC_VERSION;
		$state['current_version']   = EMAILSENDX_SYNC_VERSION;
		$state['activated_at']      = gmdate( 'c' );
		update_option( EMAILSENDX_SYNC_OPT_STATE, $state );
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * Only clears scheduled events — leaves data alone. Removing data
	 * is uninstall.php's job.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( EMAILSENDX_SYNC_CRON_HOOK );
	}

	/**
	 * Compute the next midnight in the site's configured timezone, as a
	 * UTC unix timestamp suitable for `wp_schedule_event`. ShaonPro.
	 *
	 * @return int
	 */
	protected static function next_midnight_site_time() {
		try {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$now  = new DateTime( 'now', $tz );
			$next = new DateTime( 'tomorrow 00:00:00', $tz );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			return (int) $next->getTimestamp();
		} catch ( Exception $e ) {
			// Fallback: 24h from now.
			return time() + DAY_IN_SECONDS;
		}
	}
}
