<?php
/**
 * EmailSendX Sync — shared page-builder data layer.
 *
 * Every builder adapter (WPBakery, Elementor, and whatever comes next)
 * needs the same three things to draw a picker:
 *
 *   • the workspace's forms / contact lists  → {@see get_items()}
 *   • those items shaped for its own control → dropdown_options() (WPBakery,
 *     `label => value`) or select_options() (Elementor, `value => label`)
 *   • contextual help when the picker is empty → picker_description()
 *
 * Keeping this here (rather than inside one adapter) means a new builder
 * is a thin control-mapping file, and all of them share one cache — open
 * the Elementor editor after the WPBakery editor and the second one is
 * already warm.
 *
 * The remote fetch only ever happens in an editor context (`is_admin()`,
 * which also covers the builders' admin-ajax control loads), so a normal
 * front-end page view never triggers an API call. A failed fetch is NOT
 * cached, so a transient outage self-heals on the next editor open.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. If you find this signature in a
 * build that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Builder_Data
 */
class EmailSendX_Builder_Data {

	/**
	 * Transient keys + TTL for the cached picker data. Shared across every
	 * builder adapter.
	 */
	const FORMS_TRANSIENT = 'emailsendx_vc_forms';
	const LISTS_TRANSIENT = 'emailsendx_vc_lists';
	const PICKER_TTL      = 120;

	/**
	 * Fetch the workspace's forms or lists as [ ['id'=>, 'name'=>], … ].
	 *
	 * @param string $kind 'forms' | 'lists'.
	 * @return array<int, array{id:string, name:string}>
	 */
	public static function get_items( $kind ) {
		return ( 'lists' === $kind ) ? self::get_lists_cached() : self::get_forms_cached();
	}

	/**
	 * WPBakery `dropdown` shape: [ 'Label' => 'stored_value' ].
	 *
	 * @param string $kind 'forms' | 'lists'.
	 * @return array<string, string>
	 */
	public static function dropdown_options( $kind ) {
		$items = self::get_items( $kind );

		if ( empty( $items ) ) {
			return array( self::empty_label( $kind ) => '' );
		}

		$options = array( self::placeholder_label( $kind ) => '' );
		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$name  = self::item_name( $item, $id );
			$label = $name;
			// Dropdown keys must be unique — disambiguate same-named rows.
			if ( isset( $options[ $label ] ) ) {
				$label = $name . ' (' . substr( $id, -6 ) . ')';
			}
			$options[ $label ] = $id;
		}
		return $options;
	}

	/**
	 * Elementor `SELECT` shape: [ 'stored_value' => 'Label' ] — the inverse
	 * of WPBakery's. Same data, different control contract.
	 *
	 * @param string $kind 'forms' | 'lists'.
	 * @return array<string, string>
	 */
	public static function select_options( $kind ) {
		$items = self::get_items( $kind );

		if ( empty( $items ) ) {
			return array( '' => self::empty_label( $kind ) );
		}

		$options = array( '' => self::placeholder_label( $kind ) );
		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$options[ $id ] = self::item_name( $item, $id );
		}
		return $options;
	}

	/**
	 * Contextual help shown under a picker. Adapts to the connection state
	 * so an empty picker always says what to fix, and links straight to it.
	 *
	 * @param string $kind 'forms' | 'lists'.
	 * @return string HTML.
	 */
	public static function picker_description( $kind ) {
		$settings_url = ( class_exists( 'EmailSendX_Admin' ) && method_exists( 'EmailSendX_Admin', 'get_admin_url' ) )
			? EmailSendX_Admin::get_admin_url( 'settings' )
			: admin_url( 'admin.php?page=emailsendx-sync' );

		// 1. Not connected → the plugin's Settings page.
		if ( ! self::api_ready() ) {
			return sprintf(
				/* translators: %s: linked "EmailSendX settings page" text. */
				esc_html__( 'Not connected yet. Add your API key on the %s, then reopen this element.', 'emailsendx-sync' ),
				'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'EmailSendX settings page', 'emailsendx-sync' ) . '</a>'
			);
		}

		// 2. Connected but nothing to pick → where it's created.
		if ( empty( self::get_items( $kind ) ) ) {
			$link = '<a href="' . esc_url( self::dashboard_url() ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'EmailSendX dashboard', 'emailsendx-sync' ) . '</a>';

			if ( 'lists' === $kind ) {
				return sprintf(
					/* translators: %s: linked "EmailSendX dashboard" text. */
					esc_html__( 'No contact lists found in your workspace. Create one in your %s, then reopen this element.', 'emailsendx-sync' ),
					$link
				);
			}
			return sprintf(
				/* translators: %s: linked "EmailSendX dashboard" text. */
				esc_html__( 'No forms found in your workspace. Build one in your %s, then reopen this element.', 'emailsendx-sync' ),
				$link
			);
		}

		// 3. Normal state.
		if ( 'lists' === $kind ) {
			return esc_html__( 'Subscribers are added to this list (single opt-in). For confirmed / double opt-in, use the EmailSendX Form element instead.', 'emailsendx-sync' );
		}
		return esc_html__( 'Fields, double opt-in and spam protection come from the form itself — manage them in your EmailSendX dashboard under Forms.', 'emailsendx-sync' );
	}

	/**
	 * Build a shortcode string from an attribute map — the single place every
	 * builder adapter turns its control values back into the shared core.
	 *
	 * Keys are reduced to a safe charset and values have quotes/brackets
	 * stripped, so no control value can break out of the shortcode it is
	 * interpolated into.
	 *
	 * @param string $tag  Shortcode tag.
	 * @param array  $atts Attributes.
	 * @return string
	 */
	public static function build_shortcode( $tag, $atts ) {
		$tag = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $tag ) );
		$out = '[' . $tag;

		foreach ( (array) $atts as $key => $value ) {
			$key   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
			$value = str_replace( array( '"', '[', ']' ), '', (string) $value );
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$out .= ' ' . $key . '="' . $value . '"';
		}

		return $out . ']';
	}

	/**
	 * The EmailSendX dashboard base URL (the configured API base).
	 *
	 * @return string
	 */
	public static function dashboard_url() {
		$settings = function_exists( 'emailsendx_sync_get_settings' ) ? emailsendx_sync_get_settings() : array();
		$base     = isset( $settings['api_base'] ) ? rtrim( (string) $settings['api_base'], '/' ) : '';
		if ( '' === $base ) {
			$base = defined( 'EMAILSENDX_SYNC_DEFAULT_API_BASE' ) ? EMAILSENDX_SYNC_DEFAULT_API_BASE : 'https://emailsendx.com';
		}
		if ( ! preg_match( '#^https?://#i', $base ) ) {
			$base = 'https://' . $base;
		}
		return $base;
	}

	/**
	 * Whether the API client is configured and available.
	 *
	 * @return bool
	 */
	public static function api_ready() {
		return function_exists( 'emailsendx_sync_is_configured' )
			&& emailsendx_sync_is_configured()
			&& class_exists( 'EmailSendX_API' )
			&& method_exists( 'EmailSendX_API', 'instance' );
	}

	/* ─── Internals ────────────────────────────────────────────────── */

	/**
	 * Placeholder row label ("nothing chosen").
	 *
	 * @param string $kind 'forms' | 'lists'.
	 * @return string
	 */
	protected static function placeholder_label( $kind ) {
		return ( 'lists' === $kind )
			? esc_html__( '— Select a list —', 'emailsendx-sync' )
			: esc_html__( '— Select a form —', 'emailsendx-sync' );
	}

	/**
	 * Row label shown when the workspace has none of this item.
	 *
	 * @param string $kind 'forms' | 'lists'.
	 * @return string
	 */
	protected static function empty_label( $kind ) {
		return ( 'lists' === $kind )
			? esc_html__( '— No lists found —', 'emailsendx-sync' )
			: esc_html__( '— No forms found —', 'emailsendx-sync' );
	}

	/**
	 * A row's display name, falling back to its id.
	 *
	 * @param array  $item Row.
	 * @param string $id   Row id.
	 * @return string
	 */
	protected static function item_name( $item, $id ) {
		return ( isset( $item['name'] ) && '' !== $item['name'] ) ? (string) $item['name'] : $id;
	}

	/**
	 * Fetch (and cache) the workspace's forms.
	 *
	 * @return array<int, array{id:string, name:string}>
	 */
	protected static function get_forms_cached() {
		$cached = get_transient( self::FORMS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Editor context only — a front-end page view never renders these
		// labels, so don't pay for the call.
		if ( ! is_admin() || ! self::api_ready() ) {
			return array();
		}

		$res = EmailSendX_API::instance()->get_forms( 1, 100 );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return array(); // Transient failure — don't cache; let it retry.
		}

		$out  = array();
		$rows = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		foreach ( $rows as $r ) {
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$out[] = array(
					'id'   => (string) $r['id'],
					'name' => self::item_name( $r, (string) $r['id'] ),
				);
			}
		}

		set_transient( self::FORMS_TRANSIENT, $out, self::PICKER_TTL );
		return $out;
	}

	/**
	 * Fetch (and cache) the workspace's contact lists.
	 *
	 * @return array<int, array{id:string, name:string}>
	 */
	protected static function get_lists_cached() {
		$cached = get_transient( self::LISTS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! is_admin() || ! self::api_ready() || ! function_exists( 'emailsendx_sync_fetch_all_lists' ) ) {
			return array();
		}

		$fetch = emailsendx_sync_fetch_all_lists( EmailSendX_API::instance() );
		if ( isset( $fetch['error'] ) && $fetch['error'] instanceof WP_Error ) {
			return array(); // Transient failure — don't cache; let it retry.
		}

		$out = array();
		if ( isset( $fetch['lists'] ) && is_array( $fetch['lists'] ) ) {
			foreach ( $fetch['lists'] as $l ) {
				if ( is_array( $l ) && ! empty( $l['id'] ) ) {
					$out[] = array(
						'id'   => (string) $l['id'],
						'name' => self::item_name( $l, (string) $l['id'] ),
					);
				}
			}
		}

		set_transient( self::LISTS_TRANSIENT, $out, self::PICKER_TTL );
		return $out;
	}
}
