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

		// 4. Target list — must carry a valid signature.
		//
		//    SECURITY: the list id is public (it's in the page HTML), so on its
		//    own it would let anyone POST subscribers into ANY list in the
		//    workspace, not only the one the site owner chose to embed. Each
		//    rendered form therefore ships a signature ({@see list_token()}),
		//    and we reject any list the site didn't actually sign. An attacker
		//    can replay a list the owner embedded, but cannot forge a new one.
		$list_id = isset( $params['listId'] ) ? sanitize_text_field( (string) $params['listId'] ) : '';
		$token   = isset( $params['listToken'] ) ? sanitize_text_field( (string) $params['listToken'] ) : '';
		if ( '' === $list_id ) {
			return self::err( __( 'This form is misconfigured (no list).', 'emailsendx-sync' ), 400 );
		}
		if ( ! hash_equals( self::list_token( $list_id ), $token ) ) {
			return self::err( __( 'This form could not be verified.', 'emailsendx-sync' ), 403 );
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

		// Consent source is set SERVER-SIDE, never taken from the request —
		// a forgeable provenance string would undermine the consent record.
		$res = EmailSendX_API::instance()->bulk_upsert_contacts(
			array(
				'listId'        => $list_id,
				'consentSource' => self::CONSENT_SOURCE,
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

	/**
	 * Signature that proves a list was embedded BY THIS SITE.
	 *
	 * Keyed on the site's auth salt, so it's stable across requests but not
	 * forgeable without the secret. The newsletter shortcode emits this
	 * alongside the list id, and {@see handle()} requires it. ShaonPro.
	 *
	 * @param string $list_id List id.
	 * @return string
	 */
	public static function list_token( $list_id ) {
		return hash_hmac( 'sha256', 'esx_list:' . (string) $list_id, wp_salt( 'auth' ) );
	}

	/* ─── Helpers ──────────────────────────────────────────────────── */

	/**
	 * Fixed-window per-IP rate limiter using a transient counter.
	 *
	 * @return bool True if the caller is over the limit.
	 */
	protected static function is_rate_limited() {
		// Per-IP cap.
		if ( self::bump_window( 'emailsendx_sub_rl_' . md5( self::client_ip() ), self::RL_MAX ) ) {
			return true;
		}

		// Global cap — defence in depth. Even if an attacker rotates IPs
		// (e.g. through a proxy pool), the endpoint as a whole can't be used
		// to inject more than this many contacts per window. Generous by
		// default; a high-traffic site can raise it.
		$global_max = (int) apply_filters( 'emailsendx_subscribe_global_limit', 120 );
		if ( self::bump_window( 'emailsendx_sub_rl_global', $global_max ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Fixed-window counter. Returns true when the window is already at/over
	 * the cap. Stores {count, expires} so the window does NOT slide — the
	 * expiry is set once and preserved until it lapses.
	 *
	 * @param string $key Transient key.
	 * @param int    $max Cap for the window.
	 * @return bool Over the cap?
	 */
	protected static function bump_window( $key, $max ) {
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['exp'] ) || $data['exp'] <= $now ) {
			$data = array( 'c' => 0, 'exp' => $now + self::RL_WINDOW );
		}

		if ( $data['c'] >= $max ) {
			return true;
		}

		$data['c']++;
		set_transient( $key, $data, max( 1, $data['exp'] - $now ) );
		return false;
	}

	/**
	 * Client IP for rate limiting.
	 *
	 * SECURITY: `REMOTE_ADDR` is the only value the web server actually
	 * observed and cannot be spoofed. `X-Forwarded-For` is a request header —
	 * on a site reachable directly, a caller can set a fresh one per request
	 * and defeat a per-IP limit entirely — so we ignore it UNLESS REMOTE_ADDR
	 * is a proxy the site owner has explicitly declared trusted (Cloudflare, a
	 * load balancer, etc.) via the `emailsendx_trusted_proxies` filter. Only
	 * then is the left-most XFF entry meaningful.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		$remote = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		$trusted = (array) apply_filters( 'emailsendx_trusted_proxies', array() );

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && in_array( $remote, $trusted, true ) ) {
			$xff   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts = explode( ',', $xff );
			$first = trim( $parts[0] );
			if ( '' !== $first ) {
				return $first;
			}
		}

		return $remote;
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
