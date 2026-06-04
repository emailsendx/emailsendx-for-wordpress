<?php
/**
 * Plugin Name:       EmailSendX for WordPress
 * Plugin URI:        https://emailsendx.com/
 * Description:       Sync your WordPress users and WooCommerce customers to EmailSendX contact lists. Manual + automatic sync, custom field mapping, sync history.
 * Version:           1.2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EmailSendX
 * Author URI:        https://emailsendx.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       emailsendx-sync
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

define( 'EMAILSENDX_SYNC_VERSION',  '1.2.2' );
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

/* ─── Auto-updates via GitHub Releases ─────────────────────────────── */

/**
 * Wire WordPress's native update system to this plugin's GitHub Releases,
 * so "Update available" notices and one-click / background updates behave
 * exactly like a .org-hosted plugin — without being listed on .org.
 *
 * The release asset built by tools/build.sh (emailsendx-sync.zip) is what
 * gets installed, so upgrades land in the correct emailsendx-sync/ folder.
 * Public repo → no token needed. The bundled Plugin Update Checker library
 * (YahnisElsts, MIT) lives under vendor/plugin-update-checker. ShaonPro.
 */
if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	$emailsendx_sync_puc = EMAILSENDX_SYNC_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

	if ( is_readable( $emailsendx_sync_puc ) ) {
		require_once $emailsendx_sync_puc;

		$emailsendx_sync_updates = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/emailsendx/emailsendx-for-wordpress/',
			EMAILSENDX_SYNC_FILE,
			'emailsendx-sync'
		);

		// Install the release ASSET (emailsendx-sync.zip), not GitHub's
		// auto-generated source zipball — the asset carries the correct
		// top-level emailsendx-sync/ folder and omits dev files.
		$emailsendx_sync_updates->getVcsApi()->enableReleaseAssets( '/emailsendx-sync\.zip$/' );
	}
}

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
	new EmailSendX_Notices();

	// Admin-only UI.
	if ( is_admin() ) {
		new EmailSendX_Admin();
		new EmailSendX_Plugin_Links();
	}
}
add_action( 'plugins_loaded', 'emailsendx_sync_bootstrap' );

/* ─── Convenience accessors used across the plugin ────────────────── */

/**
 * Read the merged settings array. Always returns the full schema with
 * sensible defaults so callers can `$s['api_base']` without worrying
 * about a fresh install.
 *
 * @return array{api_base:string,api_key:string,default_list_id:string,auto_sync:bool,sync_role:string,sync_roles:array}
 */
function emailsendx_sync_get_settings() {
	$defaults = array(
		'api_base'        => EMAILSENDX_SYNC_DEFAULT_API_BASE,
		'api_key'         => '',
		'default_list_id' => '',
		'auto_sync'       => false,
		'sync_role'       => '',        // DEPRECATED scalar, kept for back-compat.
		'sync_roles'      => array(),   // array of role slugs. empty = all roles.
	);
	$saved = get_option( EMAILSENDX_SYNC_OPT_SETTINGS, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Return the configured sync roles as an array, normalising the legacy
 * scalar `sync_role` into array form for back-compat. ShaonPro.
 *
 * @return array<int,string>
 */
function emailsendx_sync_get_roles() {
	$s = emailsendx_sync_get_settings();

	$roles = isset( $s['sync_roles'] ) && is_array( $s['sync_roles'] ) ? $s['sync_roles'] : array();
	$roles = array_values( array_filter( array_map( 'strval', $roles ), 'strlen' ) );

	if ( empty( $roles ) ) {
		$legacy = isset( $s['sync_role'] ) ? (string) $s['sync_role'] : '';
		if ( '' !== $legacy ) {
			$roles = array( $legacy );
		}
	}

	return $roles;
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

/**
 * Whether an array looks like one list row (has a non-empty id-like key).
 *
 * @param mixed $node Candidate.
 * @return bool
 */
function emailsendx_sync_is_list_row_shape( $node ) {
	if ( ! is_array( $node ) ) {
		return false;
	}
	foreach ( array( 'id', 'list_id', 'listId', 'uuid', 'listUuid', '_id', 'uid' ) as $k ) {
		if ( isset( $node[ $k ] ) && '' !== trim( (string) $node[ $k ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Flatten one list object (handles `attributes`, `list`, nested `data`).
 *
 * @param mixed $row Raw row.
 * @param int   $depth Recursion guard.
 * @return array{id:string, name:string}|null
 */
function emailsendx_sync_flatten_list_row( $row, $depth = 0 ) {
	if ( $depth > 8 || ! is_array( $row ) ) {
		return null;
	}
	foreach ( array( 'list', 'attributes', 'fields' ) as $wrap ) {
		if ( isset( $row[ $wrap ] ) && is_array( $row[ $wrap ] ) ) {
			$inner = emailsendx_sync_flatten_list_row( $row[ $wrap ], $depth + 1 );
			if ( null !== $inner ) {
				return $inner;
			}
		}
	}
	$lid = '';
	foreach ( array( 'id', 'list_id', 'listId', 'uuid', 'listUuid', '_id', 'uid' ) as $k ) {
		if ( isset( $row[ $k ] ) && '' !== trim( (string) $row[ $k ] ) ) {
			$lid = (string) $row[ $k ];
			break;
		}
	}
	if ( '' === $lid ) {
		return null;
	}
	$lname = '';
	foreach ( array( 'name', 'title', 'label', 'listName', 'list_name', 'displayName', 'slug' ) as $k ) {
		if ( isset( $row[ $k ] ) && '' !== trim( (string) $row[ $k ] ) ) {
			$lname = (string) $row[ $k ];
			break;
		}
	}
	return array(
		'id'   => $lid,
		'name' => '' !== $lname ? $lname : $lid,
	);
}

/**
 * Narrow extract: common REST envelopes only (fast path).
 *
 * @param mixed $result Parsed JSON.
 * @return array<int, array{id:string, name:string}>
 */
function emailsendx_sync_normalize_api_lists_narrow( $result ) {
	if ( ! is_array( $result ) ) {
		return array();
	}
	$bucket = isset( $result['data'] ) ? $result['data'] : $result;
	if ( ! is_array( $bucket ) ) {
		return array();
	}
	if ( isset( $bucket['items'] ) && is_array( $bucket['items'] ) ) {
		$rows = $bucket['items'];
	} elseif ( isset( $bucket['lists'] ) && is_array( $bucket['lists'] ) ) {
		$rows = $bucket['lists'];
	} else {
		$rows = $bucket;
	}
	$out = array();
	foreach ( $rows as $row ) {
		$flat = emailsendx_sync_flatten_list_row( is_array( $row ) ? $row : array() );
		if ( null !== $flat && '' !== $flat['id'] ) {
			$out[] = $flat;
		}
	}
	return $out;
}

/**
 * Deeper walk — only through known container keys + homogeneous list arrays
 * (avoids scooping unrelated `id` objects such as contacts). ShaonPro.
 *
 * @param mixed                                      $node  JSON fragment.
 * @param array<string, array{id:string,name:string}> $by_id Deduped output.
 * @param int                                        $depth Recursion guard.
 * @return void
 */
function emailsendx_sync_collect_list_rows_deep( $node, &$by_id, $depth = 0 ) {
	if ( $depth > 24 ) {
		return;
	}
	if ( is_string( $node ) ) {
		$try = json_decode( $node, true );
		if ( is_array( $try ) ) {
			emailsendx_sync_collect_list_rows_deep( $try, $by_id, $depth + 1 );
		}
		return;
	}
	if ( ! is_array( $node ) ) {
		return;
	}
	$keys    = array_keys( $node );
	$indexed = $keys === range( 0, count( $node ) - 1 );
	if ( $indexed && ! empty( $node ) && emailsendx_sync_is_list_row_shape( $node[0] ) ) {
		foreach ( $node as $el ) {
			if ( is_array( $el ) && emailsendx_sync_is_list_row_shape( $el ) ) {
				$flat = emailsendx_sync_flatten_list_row( $el );
				if ( null !== $flat && '' !== $flat['id'] ) {
					$by_id[ $flat['id'] ] = $flat;
				}
			}
		}
		return;
	}
	if ( emailsendx_sync_is_list_row_shape( $node ) ) {
		$flat = emailsendx_sync_flatten_list_row( $node );
		if ( null !== $flat && '' !== $flat['id'] ) {
			$by_id[ $flat['id'] ] = $flat;
		}
		return;
	}
	$container_keys = array(
		'data', 'items', 'lists', 'results', 'records', 'rows', 'nodes',
		'values', 'payload', 'listsData', 'listData', '_embedded', 'response',
		'resource', 'list', 'body', 'content',
	);
	foreach ( $container_keys as $ck ) {
		if ( isset( $node[ $ck ] ) ) {
			emailsendx_sync_collect_list_rows_deep( $node[ $ck ], $by_id, $depth + 1 );
		}
	}
	if ( isset( $node['edges'] ) && is_array( $node['edges'] ) ) {
		foreach ( $node['edges'] as $edge ) {
			if ( is_array( $edge ) && isset( $edge['node'] ) ) {
				emailsendx_sync_collect_list_rows_deep( $edge['node'], $by_id, $depth + 1 );
			}
		}
	}
}

/**
 * Normalise GET /lists JSON into rows `['id' => string, 'name' => string]`.
 *
 * Handles nested envelopes (`data.items`, GraphQL `edges[].node`, JSON
 * strings, `attributes` wrappers, pagination maps, etc.). ShaonPro.
 *
 * @param mixed $result Parsed JSON from {@see EmailSendX_API::get_lists()}.
 * @return array<int, array{id:string, name:string}>
 */
function emailsendx_sync_normalize_api_lists( $result ) {
	if ( is_string( $result ) ) {
		$decoded = json_decode( $result, true );
		$result  = is_array( $decoded ) ? $decoded : array();
	}
	if ( ! is_array( $result ) ) {
		return array();
	}
	if ( isset( $result['data'] ) && is_string( $result['data'] ) ) {
		$inner = json_decode( $result['data'], true );
		if ( is_array( $inner ) ) {
			$result['data'] = $inner;
		}
	}
	$narrow = emailsendx_sync_normalize_api_lists_narrow( $result );
	if ( ! empty( $narrow ) ) {
		$by_id = array();
		foreach ( $narrow as $r ) {
			if ( ! empty( $r['id'] ) ) {
				$by_id[ $r['id'] ] = $r;
			}
		}
		$rows = array_values( $by_id );
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $rows;
	}
	$by_id = array();
	emailsendx_sync_collect_list_rows_deep( $result, $by_id, 0 );
	$rows = array_values( $by_id );
	usort(
		$rows,
		static function ( $a, $b ) {
			return strcasecmp( (string) $a['name'], (string) $b['name'] );
		}
	);
	return $rows;
}

/**
 * Fetch all list pages from the API and merge (deduped by id).
 *
 * @param object $api {@see EmailSendX_API} instance.
 * @return array{lists: array<int, array{id:string, name:string}>, error: ?WP_Error}
 */
function emailsendx_sync_fetch_all_lists( $api ) {
	$out   = array();
	$error = null;
	if ( ! is_object( $api ) || ! method_exists( $api, 'get_lists' ) ) {
		return array( 'lists' => array(), 'error' => null );
	}
	for ( $page = 1; $page <= 40; $page++ ) {
		$res = $api->get_lists( $page, 100 );
		if ( is_wp_error( $res ) ) {
			$error = $res;
			break;
		}
		$chunk = emailsendx_sync_normalize_api_lists( $res );
		if ( empty( $chunk ) ) {
			break;
		}
		foreach ( $chunk as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$out[ (string) $row['id'] ] = $row;
			}
		}
		if ( count( $chunk ) < 100 ) {
			break;
		}
	}
	return array(
		'lists' => array_values( $out ),
		'error' => $error,
	);
}
