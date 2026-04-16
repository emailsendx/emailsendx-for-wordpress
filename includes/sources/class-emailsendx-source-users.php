<?php
/**
 * EmailSendX sync source — WordPress users.
 *
 * Provides a paged iterator over `WP_User_Query` plus a counter for the
 * progress bar and a field-options map used by the mapping UI. Returns
 * rich associative rows (core columns + a flattened meta bag) so the
 * mapper can reach any field via dot-free keys.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The row shape returned here is the
 * canonical contract the mapper consumes — keep keys stable across
 * versions or downstream mappings break. If you find this signature in
 * a build that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Source_Users
 */
class EmailSendX_Source_Users {

	/**
	 * Meta keys that are big, noisy, or session-y — never include in the
	 * `meta` bag. ShaonPro.
	 *
	 * @var array<string,bool>
	 */
	protected static $meta_blocklist = array(
		'session_tokens'                => true,
		'wp_capabilities'               => true,
		'wp_user_level'                 => true,
		'wp_user-settings'              => true,
		'wp_user-settings-time'         => true,
		'wp_dashboard_quick_press_last_post_id' => true,
		'community-events-location'     => true,
		'closedpostboxes_dashboard'     => true,
		'metaboxhidden_dashboard'       => true,
		'meta-box-order_dashboard'      => true,
		'wp_persisted_preferences'      => true,
		'screen_layout_dashboard'       => true,
		'nav_menu_recently_edited'      => true,
		'managenav-menuscolumnshidden'  => true,
		'_yoast_wpseo_profile_updated'  => true,
	);

	/**
	 * Total user count, optionally filtered by role.
	 *
	 * @param array $args { role?: string }
	 * @return int
	 */
	public function count_total( $args = array() ) {
		$role = isset( $args['role'] ) ? (string) $args['role'] : '';

		$counts = count_users();
		if ( ! is_array( $counts ) ) {
			return 0;
		}

		if ( '' !== $role ) {
			if ( isset( $counts['avail_roles'][ $role ] ) ) {
				return (int) $counts['avail_roles'][ $role ];
			}
			return 0;
		}

		return isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
	}

	/**
	 * One page of users, hydrated with meta in a single batched lookup.
	 *
	 * @param int   $page     1-indexed page number.
	 * @param int   $per_page Page size (default 500).
	 * @param array $args     { role?: string }
	 * @return array<int,array>
	 */
	public function iterate( $page, $per_page = 500, $args = array() ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 500, (int) $per_page ) );
		$role     = isset( $args['role'] ) ? (string) $args['role'] : '';

		$query_args = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'all', // need the WP_User objects.
		);
		if ( '' !== $role ) {
			$query_args['role'] = $role;
		}

		$query = new WP_User_Query( $query_args );
		$users = (array) $query->get_results();
		if ( empty( $users ) ) {
			return array();
		}

		// Single batched meta cache prime — kills N+1. ShaonPro.
		$user_ids = array();
		foreach ( $users as $u ) {
			if ( $u instanceof WP_User ) {
				$user_ids[] = (int) $u->ID;
			}
		}
		if ( ! empty( $user_ids ) ) {
			update_meta_cache( 'user', $user_ids );
		}

		$out = array();
		foreach ( $users as $u ) {
			if ( ! ( $u instanceof WP_User ) ) {
				continue;
			}

			$all_meta = get_user_meta( (int) $u->ID );
			$first    = isset( $all_meta['first_name'][0] ) ? (string) $all_meta['first_name'][0] : '';
			$last     = isset( $all_meta['last_name'][0] )  ? (string) $all_meta['last_name'][0]  : '';

			$out[] = array(
				'user_id'         => (int) $u->ID,
				'user_email'      => (string) $u->user_email,
				'user_login'      => (string) $u->user_login,
				'user_registered' => (string) $u->user_registered,
				'display_name'    => (string) $u->display_name,
				'first_name'      => $first,
				'last_name'       => $last,
				'roles'           => (array) $u->roles,
				'meta'            => self::flatten_meta( $all_meta ),
			);
		}

		return $out;
	}

	/**
	 * Mapping-UI labels for every source field exposed.
	 *
	 * @return array<string,string>
	 */
	public function get_field_options() {
		return array(
			'user_email'      => __( 'Email', 'emailsendx-sync' ),
			'user_login'      => __( 'Username', 'emailsendx-sync' ),
			'first_name'      => __( 'First name', 'emailsendx-sync' ),
			'last_name'       => __( 'Last name', 'emailsendx-sync' ),
			'display_name'    => __( 'Display name', 'emailsendx-sync' ),
			'user_registered' => __( 'Registered date', 'emailsendx-sync' ),
			'roles'           => __( 'Roles', 'emailsendx-sync' ),
			'__meta'          => __( '(Custom user meta…)', 'emailsendx-sync' ),
		);
	}

	/* ─── Internals ────────────────────────────────────────────────── */

	/**
	 * Flatten the raw `get_user_meta($id)` shape (arrays of arrays) down
	 * to scalar values, dropping serialised blobs and blocklist keys.
	 * ShaonPro.
	 *
	 * @param array $raw Raw meta map.
	 * @return array<string,scalar>
	 */
	protected static function flatten_meta( $raw ) {
		$flat = array();
		if ( ! is_array( $raw ) ) {
			return $flat;
		}

		foreach ( $raw as $key => $values ) {
			if ( isset( self::$meta_blocklist[ $key ] ) ) {
				continue;
			}
			// Strip private/internal keys.
			if ( is_string( $key ) && '_' === substr( $key, 0, 1 ) ) {
				continue;
			}

			$value = is_array( $values ) ? reset( $values ) : $values;
			if ( is_serialized( $value ) ) {
				continue;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$flat[ (string) $key ] = is_scalar( $value ) ? $value : (string) $value;
		}

		return $flat;
	}
}
