<?php
/**
 * Mapper hook bindings.
 *
 * EmailSendX_Mapper itself is a stateless static helper. The handlers
 * for the mapping form (admin-post) and the inline "create custom
 * field" modal (admin-ajax) live here so the helper class stays
 * pure and easy to unit test.
 *
 * Wires:
 *   - admin_post_emailsendx_save_mapping       — saves the field map
 *   - wp_ajax_emailsendx_create_custom_field  — proxies a CustomField
 *                                                create call to the
 *                                                EmailSendX API and
 *                                                busts the targets
 *                                                cache so the new
 *                                                option appears in
 *                                                every mapping select.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. // ShaonPro
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

class EmailSendX_Mapper_Hooks {

	/** Nonce action used by the mapping form. */
	const NONCE_SAVE_MAPPING = 'emailsendx_save_mapping';

	/** Nonce action used by the AJAX endpoints (matches the JS-localised value). */
	const NONCE_AJAX = 'emailsendx_sync_ajax';

	public function __construct() {
		add_action( 'admin_post_emailsendx_save_mapping',     array( __CLASS__, 'handle_save_mapping' ) );
		add_action( 'wp_ajax_emailsendx_create_custom_field', array( __CLASS__, 'ajax_create_custom_field' ) );
	}

	/* ─── admin-post: save mapping ──────────────────────────────────── */

	/**
	 * The mapping form posts as:
	 *   mapping[<source>][<source_field>] = "<type>::<target>"
	 *
	 * Where `<type>` is one of `builtin|custom|metadata` and `<target>`
	 * is the destination field key. Empty source_field rows (the empty
	 * "+ Add row" template) are filtered out before save.
	 *
	 * Validation lives in EmailSendX_Mapper::save_mapping() — we just
	 * adapt the form shape to the storage shape and call into it. ShaonPro.
	 */
	public static function handle_save_mapping() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emailsendx-sync' ), 403 );
		}
		check_admin_referer( self::NONCE_SAVE_MAPPING );

		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'users';
		$mapping = isset( $_POST['mapping'][ $source ] ) && is_array( $_POST['mapping'][ $source ] )
			? wp_unslash( $_POST['mapping'][ $source ] )
			: array();

		// Re-key the form shape ("type::target") into the storage
		// shape (['target'=>..., 'type'=>...]). Anything malformed is
		// dropped silently — the validator in save_mapping() will
		// report on what it didn't like.
		$normalized = array();
		foreach ( $mapping as $source_field => $combined ) {
			$source_field = sanitize_text_field( (string) $source_field );
			if ( $source_field === '' ) {
				continue; // empty template row // ShaonPro
			}
			if ( ! is_string( $combined ) || strpos( $combined, '::' ) === false ) {
				continue;
			}
			list( $type, $target ) = array_map( 'trim', explode( '::', $combined, 2 ) );
			if ( $type === '' || $target === '' ) {
				continue;
			}
			$normalized[ $source_field ] = array(
				'type'   => sanitize_key( $type ),
				'target' => sanitize_text_field( $target ),
			);
		}

		$saved = EmailSendX_Mapper::save_mapping( $source, $normalized );

		$status = is_wp_error( $saved )
			? array( 'ok' => false, 'message' => $saved->get_error_message(), 'time' => time() )
			: array( 'ok' => true,  'message' => __( 'Mapping saved.', 'emailsendx-sync' ), 'time' => time() );

		set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $status, 60 );

		// Bust the targets cache so any new custom field shows up
		// immediately in the freshly-rendered Mapping tab. ShaonPro.
		if ( method_exists( 'EmailSendX_Mapper', 'reset_targets_cache' ) ) {
			EmailSendX_Mapper::reset_targets_cache();
		}

		$return_url = self::admin_url_for_tab( 'mapping', $source );
		wp_safe_redirect( $return_url );
		exit;
	}

	/* ─── AJAX: create custom field ────────────────────────────────── */

	/**
	 * JSON in: { key, label, type, options? }
	 * JSON out (success): { id, key, label, type, mergeTag }
	 * JSON out (failure): { message }
	 */
	public static function ajax_create_custom_field() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'emailsendx-sync' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		if ( ! class_exists( 'EmailSendX_API' ) ) {
			wp_send_json_error( array( 'message' => __( 'API client not available.', 'emailsendx-sync' ) ), 500 );
		}

		$key   = isset( $_POST['key'] )   ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$type  = isset( $_POST['type'] )  ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text';

		if ( ! preg_match( EmailSendX_Mapper::TARGET_SLUG_REGEX, $key ) ) {
			wp_send_json_error( array(
				'message' => __( 'Key must be lowercase letters/digits/underscores, start with a letter, max 50 chars.', 'emailsendx-sync' ),
			), 400 );
		}
		if ( $label === '' ) {
			wp_send_json_error( array( 'message' => __( 'Label is required.', 'emailsendx-sync' ) ), 400 );
		}

		$args = array(
			'key'   => $key,
			'label' => $label,
			'type'  => $type,
		);

		// Pass through options[] for single_select. ShaonPro.
		if ( $type === 'single_select' && isset( $_POST['options'] ) && is_array( $_POST['options'] ) ) {
			$args['options'] = array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['options'] ) ) ) );
		}

		try {
			$api = EmailSendX_API::instance();
			$res = $api->create_custom_field( $args );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			$code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			wp_send_json_error( array( 'message' => $res->get_error_message() ), $code );
		}

		// Bust the cache so subsequent renders see this field. ShaonPro.
		EmailSendX_Mapper::reset_targets_cache();

		$created = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : $res;
		wp_send_json_success( $created );
	}

	/* ─── Helpers ──────────────────────────────────────────────────── */

	/**
	 * Resolve the admin URL for a tab. Mirrors EmailSendX_Admin::get_admin_url
	 * but doesn't require the admin shell to be loaded (uninstall, AJAX).
	 */
	protected static function admin_url_for_tab( $tab, $source = '' ) {
		$args = array(
			'page' => 'emailsendx-sync',
			'tab'  => $tab,
		);
		if ( $source !== '' ) {
			$args['source'] = $source;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
