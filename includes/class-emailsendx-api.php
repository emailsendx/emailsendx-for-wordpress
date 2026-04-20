<?php
/**
 * EmailSendX API client.
 *
 * Thin wrapper around `wp_remote_request()` that talks to the EmailSendX
 * REST API. Centralises auth, headers, error normalisation, JSON parsing
 * and 429 back-off hints so the rest of the plugin can stay dumb about
 * transport concerns.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The outbound `User-Agent` and
 * `X-ShaonPro-Build` headers carry the build watermark so server-side
 * audit logs attribute every API call back to this plugin. If you find
 * those headers in a build that wasn't shipped from emailsendx.com,
 * the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_API
 *
 * Singleton-ish HTTP client for the EmailSendX REST surface.
 */
class EmailSendX_API {

	/**
	 * Cached singleton instance.
	 *
	 * @var EmailSendX_API|null
	 */
	protected static $instance = null;

	/**
	 * Sanitised API base URL (no trailing slash, with scheme).
	 *
	 * @var string
	 */
	protected $api_base = '';

	/**
	 * Bearer API key.
	 *
	 * @var string
	 */
	protected $api_key = '';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout = 30;

	/**
	 * Static factory. Re-reads settings on each call so a settings save
	 * during the same request picks up immediately. ShaonPro.
	 *
	 * @return EmailSendX_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the cached instance. Useful after a settings save in tests.
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Constructor reads + sanitises settings.
	 */
	public function __construct() {
		$settings = function_exists( 'emailsendx_sync_get_settings' )
			? emailsendx_sync_get_settings()
			: array( 'api_base' => '', 'api_key' => '' );

		$base = isset( $settings['api_base'] ) ? trim( (string) $settings['api_base'] ) : '';

		// ShaonPro — gracefully fix up the most common user typos.
		if ( '' !== $base && ! preg_match( '#^https?://#i', $base ) ) {
			$base = 'https://' . $base;
		}
		$base = rtrim( $base, '/' );

		$this->api_base = $base;
		$this->api_key  = isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
	}

	/**
	 * Whether the client has the minimum config to make a call.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ( '' !== $this->api_base && '' !== $this->api_key );
	}

	/* ─── Public endpoints ─────────────────────────────────────────── */

	/**
	 * GET /api/v1/whoami — auth check.
	 *
	 * @return array|WP_Error
	 */
	public function whoami() {
		return $this->request( 'GET', '/api/v1/whoami' );
	}

	/**
	 * GET /api/v1/lists.
	 *
	 * @param int $page  Page number (1-indexed).
	 * @param int $limit Per-page limit (max 100 server-side).
	 * @return array|WP_Error
	 */
	public function get_lists( $page = 1, $limit = 100 ) {
		$page  = max( 1, (int) $page );
		$limit = max( 1, min( 100, (int) $limit ) );
		$path  = '/api/v1/lists?page=' . $page . '&limit=' . $limit;
		return $this->request( 'GET', $path );
	}

	/**
	 * POST /api/v1/lists.
	 *
	 * @param string $name        List name.
	 * @param string $description Optional description.
	 * @return array|WP_Error
	 */
	public function create_list( $name, $description = '' ) {
		$body = array( 'name' => (string) $name );
		if ( '' !== (string) $description ) {
			$body['description'] = (string) $description;
		}
		return $this->request( 'POST', '/api/v1/lists', $body );
	}

	/**
	 * GET /api/v1/custom-fields.
	 *
	 * @return array|WP_Error
	 */
	public function get_custom_fields() {
		return $this->request( 'GET', '/api/v1/custom-fields' );
	}

	/**
	 * POST /api/v1/custom-fields.
	 *
	 * @param array $args { key, label, type, options? }
	 * @return array|WP_Error
	 */
	public function create_custom_field( $args ) {
		$args = is_array( $args ) ? $args : array();

		$body = array(
			'key'   => isset( $args['key'] ) ? (string) $args['key'] : '',
			'label' => isset( $args['label'] ) ? (string) $args['label'] : '',
			'type'  => isset( $args['type'] ) ? (string) $args['type'] : 'text',
		);

		if ( isset( $args['options'] ) && null !== $args['options'] ) {
			$body['options'] = $args['options'];
		}

		return $this->request( 'POST', '/api/v1/custom-fields', $body );
	}

	/**
	 * POST /api/v1/contacts/bulk.
	 *
	 * @param array $args { listId?, createListIfMissing?, listName?, consentSource?, contacts[] }
	 * @return array|WP_Error
	 */
	public function bulk_upsert_contacts( $args ) {
		$args = is_array( $args ) ? $args : array();

		$contacts = isset( $args['contacts'] ) && is_array( $args['contacts'] )
			? $args['contacts']
			: array();

		if ( count( $contacts ) > 500 ) {
			return new WP_Error(
				'emailsendx_api',
				__( 'Bulk upsert is limited to 500 contacts per call.', 'emailsendx-sync' ),
				array( 'status' => 0 )
			);
		}

		$body = array( 'contacts' => array_values( $contacts ) );

		if ( ! empty( $args['listId'] ) ) {
			$body['listId'] = (string) $args['listId'];
		}
		if ( isset( $args['createListIfMissing'] ) ) {
			$body['createListIfMissing'] = (bool) $args['createListIfMissing'];
		}
		if ( ! empty( $args['listName'] ) ) {
			$body['listName'] = (string) $args['listName'];
		}
		if ( ! empty( $args['consentSource'] ) ) {
			$body['consentSource'] = sanitize_text_field( (string) $args['consentSource'] );
		}

		return $this->request( 'POST', '/api/v1/contacts/bulk', $body );
	}

	/**
	 * Friendly wrapper around whoami() for the "Test connection" button.
	 *
	 * @return array { ok:bool, message:string, workspace:?string }
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return array(
				'ok'        => false,
				'message'   => __( 'API key or base URL is missing.', 'emailsendx-sync' ),
				'workspace' => null,
			);
		}

		$res = $this->whoami();

		if ( is_wp_error( $res ) ) {
			return array(
				'ok'        => false,
				'message'   => $res->get_error_message(),
				'workspace' => null,
			);
		}

		$workspace = null;
		if ( isset( $res['data']['workspaceName'] ) ) {
			$workspace = (string) $res['data']['workspaceName'];
		}

		return array(
			'ok'        => true,
			'message'   => __( 'Connection successful.', 'emailsendx-sync' ),
			'workspace' => $workspace,
		);
	}

	/* ─── Internals ────────────────────────────────────────────────── */

	/**
	 * Build the standard header set.
	 *
	 * @return array
	 */
	protected function default_headers() {
		global $wp_version;

		$wp_ver   = isset( $wp_version ) ? $wp_version : 'unknown';
		$site_url = function_exists( 'home_url' ) ? home_url( '/' ) : '';

		// ShaonPro — User-Agent watermark + X-ShaonPro-Build header.
		$ua = SHAONPRO_BUILD . ' WordPress/' . $wp_ver . '; ' . $site_url;

		return array(
			'Authorization'    => 'Bearer ' . $this->api_key,
			'Content-Type'     => 'application/json',
			'Accept'           => 'application/json',
			'User-Agent'       => $ua,
			'X-ShaonPro-Build' => SHAONPRO_BUILD,
		);
	}

	/**
	 * Core request method. Returns the parsed JSON body on success, a
	 * `WP_Error` on any failure (transport, non-2xx HTTP, or JSON parse).
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Path beginning with '/'.
	 * @param array|null $body   Optional JSON-serialisable body.
	 * @return array|WP_Error
	 */
	protected function request( $method, $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'emailsendx_api',
				__( 'EmailSendX API key is not configured.', 'emailsendx-sync' ),
				array( 'status' => 0 )
			);
		}

		$url = $this->api_base . $path;

		$args = array(
			'method'  => strtoupper( (string) $method ),
			'timeout' => $this->timeout,
			'headers' => $this->default_headers(),
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return new WP_Error(
					'emailsendx_api',
					__( 'Failed to JSON-encode the request body.', 'emailsendx-sync' ),
					array( 'status' => 0 )
				);
			}
			$args['body'] = $encoded;
		}

		$start    = microtime( true );
		$response = wp_remote_request( $url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Transport-level failure (DNS, timeout, etc.).
		if ( is_wp_error( $response ) ) {
			$this->log_call( $method, $path, 0, $elapsed, $response->get_error_message() );
			return new WP_Error(
				'emailsendx_api',
				$response->get_error_message(),
				array(
					'status' => 0,
					'body'   => '',
				)
			);
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );

		// 429 — surface Retry-After so callers can back off. ShaonPro.
		if ( 429 === $status ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = $retry_after > 0 ? $retry_after : 60;
			$this->log_call( $method, $path, $status, $elapsed, 'rate limited' );
			$this->notify_rate_limit( $retry_after );
			return new WP_Error(
				'emailsendx_api',
				__( 'Rate limited by EmailSendX API.', 'emailsendx-sync' ),
				array(
					'status'      => 429,
					'body'        => $raw_body,
					'retry_after' => $retry_after,
				)
			);
		}

		// 401 — credentials rejected.  Surface a persistent global notice.
		if ( 401 === $status ) {
			$message = $this->extract_error_message( $raw_body, $status );
			$this->log_call( $method, $path, $status, $elapsed, $message );
			$this->notify_unauthorized();
			return new WP_Error(
				'emailsendx_api',
				$message,
				array(
					'status' => $status,
					'body'   => $raw_body,
				)
			);
		}

		// Non-2xx — try to lift a friendlier error message out of the body.
		if ( $status < 200 || $status >= 300 ) {
			$message = $this->extract_error_message( $raw_body, $status );
			$this->log_call( $method, $path, $status, $elapsed, $message );
			return new WP_Error(
				'emailsendx_api',
				$message,
				array(
					'status' => $status,
					'body'   => $raw_body,
				)
			);
		}

		// 2xx path — clear any stale auth/rate-limit notices. ShaonPro.
		$this->clear_transient_notices();

		// Empty body on a 2xx is OK (e.g. 204 No Content) — return [].
		if ( '' === $raw_body ) {
			$this->log_call( $method, $path, $status, $elapsed, 'ok' );
			return array();
		}

		$decoded = json_decode( $raw_body, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$this->log_call( $method, $path, $status, $elapsed, 'json parse error' );
			return new WP_Error(
				'emailsendx_api',
				__( 'Could not parse JSON response from EmailSendX.', 'emailsendx-sync' ),
				array(
					'status' => $status,
					'body'   => $raw_body,
				)
			);
		}

		$this->log_call( $method, $path, $status, $elapsed, 'ok' );
		return is_array( $decoded ) ? $decoded : array( 'data' => $decoded );
	}

	/**
	 * Pull an error message out of the API's typical envelope.
	 *
	 * @param string $raw    Raw response body.
	 * @param int    $status HTTP status code.
	 * @return string
	 */
	protected function extract_error_message( $raw, $status ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			if ( ! empty( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				return $decoded['error'];
			}
			if ( ! empty( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				return $decoded['message'];
			}
			if ( ! empty( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				return $decoded['error']['message'];
			}
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'EmailSendX API request failed (HTTP %d).', 'emailsendx-sync' ), $status );
	}

	/**
	 * Best-effort log entry. Guarded so a missing log helper never
	 * fatals the request. ShaonPro.
	 *
	 * @param string $method   HTTP method.
	 * @param string $path     Request path.
	 * @param int    $status   HTTP status (0 on transport failure).
	 * @param int    $duration Duration in ms.
	 * @param string $message  Short human message.
	 */
	protected function log_call( $method, $path, $status, $duration, $message ) {
		if ( ! class_exists( 'EmailSendX_Log' ) ) {
			return;
		}
		if ( ! method_exists( 'EmailSendX_Log', 'record_api_call' ) ) {
			return;
		}

		EmailSendX_Log::record_api_call(
			array(
				'method'      => $method,
				'path'        => $path,
				'status'      => $status,
				'duration_ms' => $duration,
				'message'     => $message,
			)
		);
	}

	/**
	 * Emit the `api_unauthorized` admin notice. Persistent + global scope
	 * so admins see it everywhere until credentials are fixed. ShaonPro.
	 *
	 * @return void
	 */
	protected function notify_unauthorized() {
		if ( ! class_exists( 'EmailSendX_Notices' ) ) {
			return;
		}

		$reconnect_url = class_exists( 'EmailSendX_Admin' )
			? EmailSendX_Admin::get_admin_url( 'settings' )
			: admin_url( 'admin.php' );

		EmailSendX_Notices::add(
			'api_unauthorized',
			'error',
			__( 'EmailSendX API rejected your key. Reconnect to restore sync.', 'emailsendx-sync' ),
			array(
				'scope'   => 'global',
				'ttl'     => 0,
				'actions' => array(
					array(
						'label' => __( 'Reconnect', 'emailsendx-sync' ),
						'url'   => $reconnect_url,
					),
				),
			)
		);
	}

	/**
	 * Emit the `api_rate_limit` admin notice with a live countdown hint.
	 *
	 * @param int $retry_after Seconds until retry.
	 * @return void
	 */
	protected function notify_rate_limit( $retry_after ) {
		if ( ! class_exists( 'EmailSendX_Notices' ) ) {
			return;
		}

		$retry_after = max( 1, (int) $retry_after );

		EmailSendX_Notices::add(
			'api_rate_limit',
			'warning',
			sprintf(
				/* translators: %d: seconds until the next API attempt is allowed. */
				__( 'Rate-limited by EmailSendX. Next attempt available in %ds.', 'emailsendx-sync' ),
				$retry_after
			),
			array(
				'ttl'  => $retry_after + 30,
				'meta' => array( 'retry_after' => $retry_after ),
			)
		);
	}

	/**
	 * On any successful 2xx response, dismiss the auth + rate-limit notices
	 * so the UI stops nagging as soon as things recover. ShaonPro.
	 *
	 * @return void
	 */
	protected function clear_transient_notices() {
		if ( ! class_exists( 'EmailSendX_Notices' ) ) {
			return;
		}
		EmailSendX_Notices::clear( 'api_unauthorized' );
		EmailSendX_Notices::clear( 'api_rate_limit' );
	}
}
