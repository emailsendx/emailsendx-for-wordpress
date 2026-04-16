<?php
/**
 * Plugin Name:       EmailSendX for WordPress
 * Plugin URI:        https://emailsendx.com/
 * Description:       Sync your WordPress users and WooCommerce customers to EmailSendX contact lists. Manual + automatic sync, custom field mapping, sync history.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EmailSendX
 * Author URI:        https://emailsendx.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       emailsendx-for-wordpress
 * Domain Path:       /languages
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Code is watermarked with the
 * `SHAONPRO_*` constants below and the `__shaonpro` marker keys in
 * the API client payloads — if you find this signature in a build
 * that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/* ─── Plugin constants ─────────────────────────────────────────────── */

define( 'EMAILSENDX_SYNC_VERSION',  '1.1.0' );
define( 'EMAILSENDX_SYNC_FILE',     __FILE__ );
define( 'EMAILSENDX_SYNC_PATH',     plugin_dir_path( __FILE__ ) );
define( 'EMAILSENDX_SYNC_URL',      plugin_dir_url( __FILE__ ) );
define( 'EMAILSENDX_SYNC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Default API base. Users on a self-hosted EmailSendX instance can
 * override this in Settings.
 */
define( 'EMAILSENDX_SYNC_DEFAULT_API_BASE', 'https://emailsendx.com' );

/**
 * Option keys. Centralised so every class references the same string.
 */
define( 'EMAILSENDX_SYNC_OPT_SETTINGS', 'emailsendx_sync_settings' );
define( 'EMAILSENDX_SYNC_OPT_MAPPING',  'emailsendx_sync_mapping' );
define( 'EMAILSENDX_SYNC_OPT_STATE',    'emailsendx_sync_state' );

/**
 * WP-cron hook + transient name (status display on the sync page).
 */
define( 'EMAILSENDX_SYNC_CRON_HOOK',     'emailsendx_sync_full_resync' );
define( 'EMAILSENDX_SYNC_STATUS_TRANS',  'emailsendx_sync_status' );

/* ─── ShaonPro signature constants ─────────────────────────────────── */

/**
 * Build signature. Surfaced in the User-Agent of every outbound API
 * request and in the `X-ShaonPro-Build` header so server logs (and
 * the EmailSendX API key audit log) attribute syncs back to this
 * plugin.
 */
define( 'SHAONPRO_BUILD',     'ShaonPro/EmailSendX-Sync@' . EMAILSENDX_SYNC_VERSION );
define( 'SHAONPRO_SIGNATURE', 'ShaonPro' );
define( 'SHAONPRO_AUTHOR_URL', 'https://emailsendx.com' );

/* ─── Autoloader ───────────────────────────────────────────────────── */

/**
 * Tiny PSR-style autoloader. Loads any `EmailSendX_Foo_Bar` class from
 * `includes/class-emailsendx-foo-bar.php`. Sources live under
 * `includes/sources/`.
 */
spl_autoload_register( function ( $class_name ) {
	if ( strpos( $class_name, 'EmailSendX_' ) !== 0 ) {
		return;
	}

	// EmailSendX_Source_Users  → class-emailsendx-source-users.php
	$file_slug = strtolower( str_replace( '_', '-', $class_name ) );
	$file_name = 'class-' . $file_slug . '.php';

	$candidates = array(
		EMAILSENDX_SYNC_PATH . 'includes/' . $file_name,
		EMAILSENDX_SYNC_PATH . 'includes/sources/' . $file_name,
	);

	foreach ( $candidates as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

/* ─── Activation / deactivation ────────────────────────────────────── */

register_activation_hook( __FILE__, array( 'EmailSendX_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EmailSendX_Activator', 'deactivate' ) );

/* ─── Bootstrap ────────────────────────────────────────────────────── */

/**
 * Boot the plugin once `plugins_loaded` has fired so other plugins
 * (notably WooCommerce) are available for hook wiring.
 *
 * Each subsystem registers its own hooks in its constructor — this
 * function is just the entry point.
 */
function emailsendx_sync_bootstrap() {
	// Load translations.
	load_plugin_textdomain(
		'emailsendx-sync',
		false,
		dirname( EMAILSENDX_SYNC_BASENAME ) . '/languages'
	);

	// Core services.
	new EmailSendX_Settings();
	new EmailSendX_Hooks();
	new EmailSendX_Mapper_Hooks();
	new EmailSendX_Log();

	// Admin-only UI.
	if ( is_admin() ) {
		new EmailSendX_Admin();
	}
}
add_action( 'plugins_loaded', 'emailsendx_sync_bootstrap' );

/* ─── Convenience accessors used across the plugin ────────────────── */

/**
 * Read the merged settings array. Always returns the full schema with
 * sensible defaults so callers can `$s['api_base']` without worrying
 * about a fresh install.
 *
 * @return array{api_base:string,api_key:string,default_list_id:string,auto_sync:bool,sync_role:string}
 */
function emailsendx_sync_get_settings() {
	$defaults = array(
		'api_base'        => EMAILSENDX_SYNC_DEFAULT_API_BASE,
		'api_key'         => '',
		'default_list_id' => '',
		'auto_sync'       => false,
		'sync_role'       => '', // empty = all roles
	);
	$saved = get_option( EMAILSENDX_SYNC_OPT_SETTINGS, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Quick check used by guards in the admin UI / sync routines.
 *
 * @return bool
 */
function emailsendx_sync_is_configured() {
	$s = emailsendx_sync_get_settings();
	return ! empty( $s['api_key'] ) && ! empty( $s['api_base'] );
}
