<?php
/**
 * EmailSendX Sync — public newsletter subscribe proxy.
 *
 * Backs the [emailsendx_newsletter] shortcode (and the WPBakery
 * "Newsletter" element that maps onto it). A visitor's browser POSTs to
 * this plugin's own REST route; the route validates + rate-limits, then
 * upserts the contact into the chosen list using the workspace's
 * server-side API key. The key never reaches the browser.
 *
 * Why a public REST route rather than a nonce-protected admin-ajax call:
 * newsletter boxes routinely live on full-page-cached, logged-out pages,
 * where a WordPress nonce is either stale (baked into the cached HTML) or
 * meaningless (shared logged-out session). So this mirrors the posture of
 * the EmailSendX hosted form submit endpoint — the real defences are a
 * hidden honeypot field, a per-IP rate limit, and server-side email
 * validation, with the authenticated upsert as the ultimate gate. A
 * caught honeypot "succeeds" silently so bots get no signal.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The `emailsendx/v1` REST namespace
 * and `wordpress-newsletter` consent source are watermarks. If you find
 * this signature in a build that wasn't shipped from emailsendx.com, the
 * code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Subscribe
 */
class EmailSendX_Subscribe {

	/**
	 * REST namespace + route.
	 */
	const REST_NS    = 'emailsendx/v1';
	const REST_ROUTE = '/subscribe';

	/**
	 * Rate limit: max submissions per IP per window.
	 */
	const RL_MAX    = 8;
	const RL_WINDOW = 600; // 10 minutes.

	/**
	 * Default consent source recorded on upsert.
	 */
	const CONSENT_SOURCE = 'wordpress-newsletter';

	/**
	 * Wire the REST route. ShaonPro.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register POST /emailsendx/v1/subscribe (public).
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Public; protected by honeypot + rate limit below.
			)
		);
	}

	/**
	 * Handle a subscribe request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$params = is_object( $request ) && method_exists( $request, 'get_json_params' )
			? (array) $request->get_json_params()
			: array();
		if ( empty( $params ) && is_object( $request ) && method_exists( $request, 'get_body_params' ) ) {
			$params = (array) $request->get_body_params();
		}

		// 1. Honeypot — a filled `_hp_email` is a bot. Succeed silently so
		//    we don't teach the bot which field tripped it.
		$hp = isset( $params['_hp_email'] ) ? trim( (string) $params['_hp_email'] ) : '';
		if ( '' !== $hp ) {
			return self::ok();
		}

		// 2. Per-IP rate limit (fixed window via transient).
		if ( self::is_rate_limited() ) {
			return self::err( __( 'Too many requests. Please try again shortly.', 'emailsendx-sync' ), 429 );
		}

		// 3. Email validation.
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return self::err( __( 'Please enter a valid email address.', 'emailsendx-sync' ), 400 );
		}

		// 4. Target list.
		$list_id = isset( $params['listId'] ) ? sanitize_text_field( (string) $params['listId'] ) : '';
		if ( '' === $list_id ) {
			return self::err( __( 'This form is misconfigured (no list).', 'emailsendx-sync' ), 400 );
		}

		if ( ! function_exists( 'emailsendx_sync_is_configured' ) || ! emailsendx_sync_is_configured() ) {
			return self::err( __( 'Subscriptions are not available right now.', 'emailsendx-sync' ), 503 );
		}
		if ( ! class_exists( 'EmailSendX_API' ) || ! method_exists( 'EmailSendX_API', 'instance' ) ) {
			return self::err( __( 'Subscriptions are not available right now.', 'emailsendx-sync' ), 503 );
		}

		// 5. Build the contact + upsert.
		$contact = array( 'email' => $email );

		$first = isset( $params['firstName'] ) ? sanitize_text_field( (string) $params['firstName'] ) : '';
		if ( '' !== $first ) {
			$contact['firstName'] = $first;
		}

		$source = isset( $params['source'] ) ? sanitize_text_field( (string) $params['source'] ) : '';
		if ( '' === $source ) {
			$source = self::CONSENT_SOURCE;
		}

		$res = EmailSendX_API::instance()->bulk_upsert_contacts(
			array(
				'listId'        => $list_id,
				'consentSource' => $source,
				'contacts'      => array( $contact ),
			)
		);

		if ( is_wp_error( $res ) ) {
			// Don't leak internal API detail to the public; log-friendly
			// message only. The visitor just needs "try again".
			return self::err( __( 'Something went wrong. Please try again.', 'emailsendx-sync' ), 502 );
		}

		return self::ok();
	}

	/* ─── Helpers ──────────────────────────────────────────────────── */

	/**
	 * Fixed-window per-IP rate limiter using a transient counter.
	 *
	 * @return bool True if the caller is over the limit.
	 */
	protected static function is_rate_limited() {
		$ip  = self::client_ip();
		$key = 'emailsendx_sub_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::RL_MAX ) {
			return true;
		}
		// First hit in the window seeds the TTL; subsequent hits bump the
		// count but keep the original expiry (fixed window).
		set_transient( $key, $count + 1, self::RL_WINDOW );
		return false;
	}

	/**
	 * Best-effort client IP, honouring a single proxy hop.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts = explode( ',', $xff );
			$first = trim( $parts[0] );
			if ( '' !== $first ) {
				return $first;
			}
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return 'unknown';
	}

	/**
	 * A success response.
	 *
	 * @return WP_REST_Response
	 */
	protected static function ok() {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * An error response.
	 *
	 * @param string $message Public message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	protected static function err( $message, $status ) {
		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => (string) $message,
			),
			(int) $status
		);
	}
}
