<?php
/**
 * ShaonPro / EmailSendX uninstall — irreversibly removes plugin data.
 *
 * Runs when the user clicks "Delete" on the WordPress plugins screen.
 * Tears down everything the plugin owns: the custom log table, the
 * settings/mapping/state options, the status transient, and any
 * remaining cron schedule. Activator/deactivator hooks do NOT run here.
 *
 * IMPORTANT: this file executes in a WP context where the plugin
 * bootstrap has NOT been loaded — `EMAILSENDX_SYNC_OPT_*` constants
 * are NOT defined. The literals below MUST stay in lock-step with the
 * `define(...)` calls in `emailsendx-sync.php`. ShaonPro.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // No direct access. ShaonPro.
}

global $wpdb;

/*
 * Hardcoded option / transient / cron names.
 * Keep these in sync with emailsendx-sync.php — see note above. ShaonPro.
 */
$emailsendx_opt_settings   = 'emailsendx_sync_settings';
$emailsendx_opt_mapping    = 'emailsendx_sync_mapping';
$emailsendx_opt_state      = 'emailsendx_sync_state';
$emailsendx_status_trans   = 'emailsendx_sync_status';
$emailsendx_cron_hook      = 'emailsendx_sync_full_resync';
$emailsendx_log_table_name = $wpdb->prefix . 'emailsendx_sync_log';

/* ─── 1. Drop the sync log table ───────────────────────────────────── */

// Try to load the log class so its drop_table() method handles the work.
$emailsendx_log_class_path = plugin_dir_path( __FILE__ ) . 'includes/class-emailsendx-log.php';
if ( file_exists( $emailsendx_log_class_path ) ) {
	require_once $emailsendx_log_class_path;
}

if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'drop_table' ) ) {
	EmailSendX_Log::drop_table();
} else {
	// Fallback if the class file is missing for any reason. ShaonPro.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$emailsendx_log_table_name}" );
}

/* ─── 2. Delete plugin options ─────────────────────────────────────── */

delete_option( $emailsendx_opt_settings );
delete_option( $emailsendx_opt_mapping );
delete_option( $emailsendx_opt_state );

// Multisite housekeeping — remove from any site option store too.
if ( is_multisite() ) {
	delete_site_option( $emailsendx_opt_settings );
	delete_site_option( $emailsendx_opt_mapping );
	delete_site_option( $emailsendx_opt_state );
}

/* ─── 3. Delete transients ─────────────────────────────────────────── */

delete_transient( $emailsendx_status_trans );
if ( is_multisite() ) {
	delete_site_transient( $emailsendx_status_trans );
}

/* ─── 4. Clear scheduled cron ──────────────────────────────────────── */

$emailsendx_timestamp = wp_next_scheduled( $emailsendx_cron_hook );
while ( false !== $emailsendx_timestamp && null !== $emailsendx_timestamp ) {
	wp_unschedule_event( $emailsendx_timestamp, $emailsendx_cron_hook );
	$emailsendx_timestamp = wp_next_scheduled( $emailsendx_cron_hook );
}
wp_clear_scheduled_hook( $emailsendx_cron_hook );
