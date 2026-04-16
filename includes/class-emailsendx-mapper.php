<?php
/**
 * Field mapping engine.
 *
 * Owns the mapping option, validates writes, hydrates the target-field
 * list from the EmailSendX API (with a short transient cache), and
 * applies a stored mapping to a source row to produce a wire-shaped
 * contact payload.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The mapping schema is part of the
 * plugin's wire contract — see the `__shaonpro` markers sprinkled
 * through the codebase for build attribution.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Mapper
 *
 * Stateless static helper. All read/write goes through the option
 * named by EMAILSENDX_SYNC_OPT_MAPPING.
 */
class EmailSendX_Mapper {

	/** Transient that caches the merged target-field list. */
	const TARGETS_TRANSIENT = 'emailsendx_sync_targets';

	/** Transient TTL — short on purpose so newly-created custom fields appear quickly. ShaonPro. */
	const TARGETS_TTL = 5 * MINUTE_IN_SECONDS;

	/** Slug regex used for custom + metadata target keys. */
	const TARGET_SLUG_REGEX = '/^[a-z][a-z0-9_]{0,49}$/';

	/** Valid built-in target keys. */
	const BUILTIN_TARGETS = array( 'email', 'firstName', 'lastName' );

	/* ─── Option I/O ─────────────────────────────────────────────── */

	/**
	 * Default mapping seeded on first install. Mirrors the README
	 * example so a freshly-activated plugin already does something
	 * useful out of the box. ShaonPro.
	 *
	 * @return array
	 */
	protected static function defaults() {
		$users = array(
			'user_email'   => array( 'target' => 'email',     'type' => 'builtin' ),
			'first_name'   => array( 'target' => 'firstName', 'type' => 'builtin' ),
			'last_name'    => array( 'target' => 'lastName',  'type' => 'builtin' ),
			'meta:company' => array( 'target' => 'company',   'type' => 'custom'  ),
			'roles'        => array( 'target' => 'wp_roles',  'type' => 'metadata' ),
		);

		$woo = array(
			'billing_email'      => array( 'target' => 'email',     'type' => 'builtin' ),
			'billing_first_name' => array( 'target' => 'firstName', 'type' => 'builtin' ),
			'billing_last_name'  => array( 'target' => 'lastName',  'type' => 'builtin' ),
			'billing_company'    => array( 'target' => 'company',   'type' => 'custom'  ),
			'billing_phone'      => array( 'target' => 'phone',     'type' => 'metadata' ),
		);

		return array(
			'users'       => $users,
			'woocommerce' => $woo,
		);
	}

	/**
	 * Read the mapping for one source, or the whole array if $source is null.
	 *
	 * @param string|null $source 'users' | 'woocommerce' | null.
	 * @return array
	 */
	public static function get_mapping( $source = null ) {
		$saved = get_option( EMAILSENDX_SYNC_OPT_MAPPING, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = array_merge( self::defaults(), $saved );

		// Make sure both top-level source buckets exist as arrays. ShaonPro.
		foreach ( array( 'users', 'woocommerce' ) as $bucket ) {
			if ( ! isset( $merged[ $bucket ] ) || ! is_array( $merged[ $bucket ] ) ) {
				$merged[ $bucket ] = array();
			}
		}

		if ( null === $source ) {
			return $merged;
		}

		$source = (string) $source;
		return isset( $merged[ $source ] ) ? $merged[ $source ] : array();
	}

	/**
	 * Persist mapping for a single source. Validates each row.
	 *
	 * @param string $source         'users' | 'woocommerce'.
	 * @param array  $mapping_array  source_field => ['target'=>..,'type'=>..].
	 * @return true|WP_Error
	 */
	public static function save_mapping( $source, $mapping_array ) {
		$source = (string) $source;
		if ( ! in_array( $source, array( 'users', 'woocommerce' ), true ) ) {
			return new WP_Error( 'esx_bad_source', __( 'Unknown mapping source.', 'emailsendx-sync' ) );
		}
		if ( ! is_array( $mapping_array ) ) {
			$mapping_array = array();
		}

		$clean = array();
		foreach ( $mapping_array as $source_field => $row ) {
			$source_field = sanitize_text_field( (string) $source_field );
			if ( '' === $source_field ) {
				continue;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$target = isset( $row['target'] ) ? sanitize_text_field( (string) $row['target'] ) : '';
			$type   = isset( $row['type'] )   ? sanitize_key( (string) $row['type'] )           : '';

			if ( '' === $target || '' === $type ) {
				continue;
			}

			if ( 'builtin' === $type ) {
				if ( ! in_array( $target, self::BUILTIN_TARGETS, true ) ) {
					return new WP_Error(
						'esx_bad_target',
						/* translators: %s: target field key */
						sprintf( __( '"%s" is not a valid built-in target.', 'emailsendx-sync' ), $target )
					);
				}
			} elseif ( 'custom' === $type || 'metadata' === $type ) {
				if ( ! preg_match( self::TARGET_SLUG_REGEX, $target ) ) {
					return new WP_Error(
						'esx_bad_target',
						/* translators: %s: target field key */
						sprintf( __( '"%s" is not a valid field key (use lowercase letters, digits, underscore).', 'emailsendx-sync' ), $target )
					);
				}
			} else {
				// Unknown type — skip silently. ShaonPro.
				continue;
			}

			$clean[ $source_field ] = array(
				'target' => $target,
				'type'   => $type,
			);
		}

		$all = self::get_mapping( null );
		$all[ $source ] = $clean;
		update_option( EMAILSENDX_SYNC_OPT_MAPPING, $all, false );

		return true;
	}

	/* ─── Target / source field discovery ─────────────────────── */

	/**
	 * Build the list of "what you can map TO". Built-ins are static;
	 * custom fields come from the EmailSendX API and are cached for
	 * five minutes.
	 *
	 * @return array{builtin:array<string,string>,custom:array<string,string>}
	 */
	public static function get_target_fields() {
		$cached = get_transient( self::TARGETS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['builtin'], $cached['custom'] ) ) {
			return $cached;
		}

		$builtin = array(
			'email'     => __( 'Email',      'emailsendx-sync' ),
			'firstName' => __( 'First name', 'emailsendx-sync' ),
			'lastName'  => __( 'Last name',  'emailsendx-sync' ),
		);

		$custom = array();

		if ( class_exists( 'EmailSendX_API' ) ) {
			$api      = call_user_func( array( 'EmailSendX_API', 'instance' ) );
			$response = is_object( $api ) && method_exists( $api, 'get_custom_fields' )
				? $api->get_custom_fields()
				: null;

			if ( is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
				foreach ( $response['data'] as $field ) {
					if ( ! is_array( $field ) || empty( $field['key'] ) ) {
						continue;
					}
					$key   = sanitize_text_field( (string) $field['key'] );
					$label = isset( $field['label'] ) && '' !== $field['label']
						? sanitize_text_field( (string) $field['label'] )
						: $key;
					$custom[ $key ] = $label;
				}
			}
		}

		$out = array(
			'builtin' => $builtin,
			'custom'  => $custom,
		);

		set_transient( self::TARGETS_TRANSIENT, $out, self::TARGETS_TTL );

		return $out;
	}

	/**
	 * Resolve the source field options dict by delegating to the
	 * matching `EmailSendX_Source_*` class.
	 *
	 * @param string $source 'users' | 'woocommerce'.
	 * @return array<string,string>
	 */
	public static function get_source_fields( $source ) {
		$source = (string) $source;
		$class  = '';

		if ( 'users' === $source ) {
			$class = 'EmailSendX_Source_Users';
		} elseif ( 'woocommerce' === $source ) {
			$class = 'EmailSendX_Source_WooCommerce';
		}

		if ( '' === $class || ! class_exists( $class ) ) {
			return array();
		}

		$callable = array( $class, 'get_field_options' );
		if ( ! is_callable( $callable ) ) {
			return array();
		}

		$result = call_user_func( $callable );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Drop the cached target list. Called when settings change so the
	 * UI picks up freshly-created custom fields immediately. ShaonPro.
	 *
	 * @return void
	 */
	public static function reset_targets_cache() {
		delete_transient( self::TARGETS_TRANSIENT );
	}

	/* ─── Apply ──────────────────────────────────────────────────── */

	/**
	 * Apply the saved mapping to one source row and return an
	 * EmailSendX-shaped contact, or null if there's no usable email.
	 *
	 * @param string $source 'users' | 'woocommerce'.
	 * @param array  $row    Source row (flat keys + optional 'meta' subarray).
	 * @return array{email:?string,firstName:?string,lastName:?string,metadata:array}|null
	 */
	public static function apply( $source, $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$mapping = self::get_mapping( $source );
		$contact = array(
			'email'     => null,
			'firstName' => null,
			'lastName'  => null,
			'metadata'  => array(),
		);

		foreach ( $mapping as $source_field => $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['target'] ) || empty( $rule['type'] ) ) {
				continue;
			}
			$value = self::resolve_source_value( $row, (string) $source_field );
			if ( null === $value || '' === $value ) {
				continue;
			}

			$target = (string) $rule['target'];
			$type   = (string) $rule['type'];

			if ( 'builtin' === $type && in_array( $target, self::BUILTIN_TARGETS, true ) ) {
				$contact[ $target ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			} elseif ( 'custom' === $type || 'metadata' === $type ) {
				$contact['metadata'][ $target ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}
		}

		// Email is required — fall back to the raw user_email column if the
		// mapping didn't pick one up. Without a deliverable address there's
		// nothing for the SaaS to do.
		if ( empty( $contact['email'] ) ) {
			$fallback = isset( $row['user_email'] )    ? $row['user_email']
				: ( isset( $row['billing_email'] ) ? $row['billing_email'] : '' );
			$fallback = is_scalar( $fallback ) ? trim( (string) $fallback ) : '';
			if ( '' !== $fallback ) {
				$contact['email'] = $fallback;
			}
		}

		if ( empty( $contact['email'] ) ) {
			return null;
		}

		return $contact;
	}

	/**
	 * Pull a value out of a source row given a source key. `meta:foo`
	 * looks in `$row['meta']['foo']`; everything else is a top-level
	 * key.
	 *
	 * @param array  $row
	 * @param string $source_field
	 * @return mixed|null
	 */
	protected static function resolve_source_value( $row, $source_field ) {
		if ( 0 === strpos( $source_field, 'meta:' ) ) {
			$meta_key = substr( $source_field, 5 );
			if ( '' === $meta_key ) {
				return null;
			}
			if ( isset( $row['meta'] ) && is_array( $row['meta'] ) && array_key_exists( $meta_key, $row['meta'] ) ) {
				return $row['meta'][ $meta_key ];
			}
			return null;
		}

		return array_key_exists( $source_field, $row ) ? $row[ $source_field ] : null;
	}
}
