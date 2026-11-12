<?php
/**
 * WooCommerce abandoned-cart recovery.
 *
 * Off by default. When the merchant turns it on (the Carts tab) the
 * plugin:
 *
 *   1. Remembers each shopper's cart — once it knows their email and they
 *      are allowed to get reminders (see "Who can get reminders") — in its
 *      own table, with a private link that rebuilds the cart.
 *   2. Checks every five minutes (WP-Cron) for carts nobody touched for the
 *      configured time and, for each, adds the shopper to the
 *      "Abandoned carts" list in EmailSendX and sends a `cart_abandoned`
 *      event with the cart link and items. EmailSendX's Abandoned Cart
 *      sequence takes it from there.
 *   3. Sends `purchase_completed` when an order is paid for, so EmailSendX
 *      stops that shopper's reminders.
 *
 * Nothing is stored for a shopper who declined reminders, and rows are
 * deleted after {@see EmailSendX_Cart::RETENTION_DAYS} days.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. If you find this file in a build
 * that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Cart
 */
class EmailSendX_Cart {

	/** Bump when the table definition changes; dbDelta runs on mismatch. */
	const DB_VERSION = '2';

	/** Recurring scan for idle carts. */
	const CRON_HOOK = 'emailsendx_sync_cart_scan';

	/** One-off event that reports a paid order. Args: [ order_id ]. */
	const PURCHASE_HOOK = 'emailsendx_sync_cart_purchase';

	/** Custom WP-Cron interval name (five minutes). */
	const CRON_SCHEDULE = 'emailsendx_sync_five_minutes';

	/** Query-string key of the restore link: ?esx_cart=<token>. */
	const RESTORE_PARAM = 'esx_cart';

	/** EmailSendX list cart shoppers are added to (created on first use). */
	const LIST_NAME = 'Abandoned carts';

	/** Recorded on the contact as where their consent came from. */
	const CONSENT_SOURCE = 'woocommerce-abandoned-cart';

	/** Event names — these match EmailSendX's automation templates. */
	const EVENT_ABANDONED = 'cart_abandoned';
	const EVENT_PURCHASE  = 'purchase_completed';

	/** Rows older than this are deleted by the scan. */
	const RETENTION_DAYS = 30;

	/** Carts reported per scan run. */
	const BATCH = 20;

	/** Send attempts before a cart (or an order) is given up on. */
	const MAX_ATTEMPTS = 3;

	/** Items sent with an event; EmailSendX caps the payload at 16 KB. */
	const MAX_ITEMS = 20;

	/** Size the event payload is trimmed to, safely under that cap. */
	const MAX_EVENT_BYTES = 14000;

	/** WooCommerce session keys for a guest's captured details. */
	const SESSION_EMAIL   = 'emailsendx_cart_email';
	const SESSION_FIRST   = 'emailsendx_cart_first';
	const SESSION_LAST    = 'emailsendx_cart_last';
	const SESSION_CONSENT = 'emailsendx_cart_consent';

	/** A logged-in customer's choice ('yes' / 'no'). */
	const USER_META_CONSENT = '_emailsendx_cart_consent';

	/** Order meta: when `purchase_completed` was sent, and tries so far. */
	const ORDER_META_SENT  = '_emailsendx_purchase_sent';
	const ORDER_META_TRIES = '_emailsendx_purchase_tries';

	/** Transient holding the result of "Send a test cart" for one minute. */
	const TEST_TRANSIENT = 'emailsendx_sync_cart_test';

	/** Nonce actions. */
	const NONCE_CAPTURE = 'emailsendx_cart_capture';
	const NONCE_TEST    = 'emailsendx_cart_test';
	const NONCE_DELETE  = 'emailsendx_cart_delete';

	/** Rows per page in the Carts tab. */
	const PER_PAGE = 20;

	/**
	 * Set when this request changed the cart or the shopper's details;
	 * the row is written once, at shutdown, after totals are final.
	 *
	 * @var bool
	 */
	protected static $dirty = false;

	/**
	 * Hook wiring. Always-on hooks (cron, privacy, admin) are registered
	 * regardless of the setting so turning the feature off cleans up after
	 * itself; shopper-facing hooks only when it is on.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( self::PURCHASE_HOOK, array( __CLASS__, 'send_purchase' ) );

		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( 'admin_post_emailsendx_cart_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_emailsendx_cart_delete', array( __CLASS__, 'handle_delete' ) );

		if ( ! self::is_active() ) {
			return;
		}

		self::maybe_install();

		// Cart changes.
		$cart_hooks = array(
			'woocommerce_add_to_cart',
			'woocommerce_cart_item_removed',
			'woocommerce_cart_item_restored',
			'woocommerce_after_cart_item_quantity_update',
			'woocommerce_cart_emptied',
			'woocommerce_applied_coupon',
			'woocommerce_removed_coupon',
		);
		foreach ( $cart_hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'mark_dirty' ), 20, 0 );
		}

		// The shopper's email. The script is the main path for guests; the
		// two server hooks pick the email up whenever checkout itself sends
		// the form to the server (classic refresh, block checkout sync).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
		add_action( 'wp_ajax_emailsendx_cart_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'wp_ajax_nopriv_emailsendx_cart_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'on_order_review' ) );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'on_store_api_customer' ), 10, 1 );

		add_action( 'shutdown', array( __CLASS__, 'persist_if_dirty' ) );

		// The link in the reminder email.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_restore' ), 5 );

		// Orders.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_created' ), 20, 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_created' ), 20, 0 );
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'on_order_paid' ), 20, 1 );
		}
	}

	/* ─── Settings ─────────────────────────────────────────────────── */

	/**
	 * Idle times the merchant can choose from, in minutes.
	 *
	 * @return int[]
	 */
	public static function idle_choices() {
		return array( 30, 60, 120, 240 );
	}

	/**
	 * A valid idle time; anything else becomes the 60-minute default.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_idle( $value ) {
		$value = (int) $value;
		return in_array( $value, self::idle_choices(), true ) ? $value : 60;
	}

	/**
	 * A valid "Who can get reminders" mode; anything else becomes 'notice'.
	 *
	 *   notice    — everyone who enters an email, unless they click "No thanks".
	 *   checkbox  — only shoppers who tick the consent box.
	 *   customers — only logged-in customers.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_mode( $value ) {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, array( 'notice', 'checkbox', 'customers' ), true ) ? $value : 'notice';
	}

	/**
	 * Whether a shopper may be tracked and reminded.
	 *
	 * @param string $mode    Consent mode.
	 * @param string $consent The shopper's choice: '', 'yes' or 'no'.
	 * @param int    $user_id WP user id, 0 for a guest.
	 * @return bool
	 */
	public static function can_track( $mode, $consent, $user_id ) {
		if ( 'no' === $consent ) {
			return false;
		}
		if ( 'checkbox' === $mode ) {
			return 'yes' === $consent;
		}
		if ( 'customers' === $mode ) {
			return (int) $user_id > 0;
		}
		return true;
	}

	/**
	 * The merchant switched the feature on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$s = emailsendx_sync_get_settings();
		return ! empty( $s['cart_enabled'] );
	}

	/**
	 * On, connected to EmailSendX, and WooCommerce is running.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::is_enabled() && class_exists( 'WooCommerce' ) && emailsendx_sync_is_configured();
	}

	/**
	 * @return int Minutes without a change before a cart counts as abandoned.
	 */
	public static function idle_minutes() {
		$s = emailsendx_sync_get_settings();
		return self::sanitize_idle( isset( $s['cart_idle_minutes'] ) ? $s['cart_idle_minutes'] : 60 );
	}

	/**
	 * @return string Consent mode.
	 */
	public static function consent_mode() {
		$s = emailsendx_sync_get_settings();
		return self::sanitize_mode( isset( $s['cart_consent_mode'] ) ? $s['cart_consent_mode'] : 'notice' );
	}

	/**
	 * This feature uses WooCommerce's order and cart APIs only, so it works
	 * with High-Performance Order Storage and the block cart / checkout.
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EMAILSENDX_SYNC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EMAILSENDX_SYNC_FILE, true );
		}
	}

	/* ─── Table ────────────────────────────────────────────────────── */

	/**
	 * @return string Fully-qualified table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'emailsendx_carts';
	}

	/**
	 * Create / upgrade the table when the stored version is behind.
	 * Activation hooks do not run on plugin updates, so this is checked
	 * at runtime (one autoloaded option read).
	 */
	public static function maybe_install() {
		$state = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		if ( isset( $state['cart_db'] ) && self::DB_VERSION === (string) $state['cart_db'] ) {
			return;
		}
		$upgrading = ! empty( $state['cart_db'] );
		self::install_table();
		$state['cart_db'] = self::DB_VERSION;
		update_option( EMAILSENDX_SYNC_OPT_STATE, $state );
		if ( $upgrading ) {
			// Order totals need WooCommerce fully loaded, which it is not yet
			// when this runs from the plugin's constructor.
			if ( did_action( 'init' ) ) {
				self::backfill_values();
			} else {
				add_action( 'init', array( __CLASS__, 'backfill_values' ), 99 );
			}
		}
	}

	/**
	 * Version 2 added the value columns. Fill them for carts saved before
	 * the upgrade, from the stored snapshot and the linked order, so the
	 * Carts tab shows amounts for them too.
	 */
	public static function backfill_values() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, cart, order_id, status, updated_at FROM {$table} WHERE cart_value = 0 ORDER BY id DESC LIMIT 1000", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$snapshot = json_decode( (string) $row['cart'], true );
			$data     = array();
			if ( is_array( $snapshot ) ) {
				$data['cart_value'] = isset( $snapshot['value'] ) ? round( (float) $snapshot['value'], 2 ) : 0;
				$data['currency']   = isset( $snapshot['currency'] ) ? self::clip( $snapshot['currency'], 8 ) : '';
			}
			if ( in_array( $row['status'], array( 'ordered', 'recovered' ), true ) ) {
				$data['closed_at'] = $row['updated_at'];
				$order             = ( (int) $row['order_id'] > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( (int) $row['order_id'] ) : null;
				if ( $order ) {
					$data['order_value'] = round( (float) $order->get_total(), 2 );
				}
			}
			if ( ! empty( $data ) ) {
				$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
			}
		}
	}

	/**
	 * dbDelta the carts table. Times are stored in UTC.
	 */
	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is fussy about whitespace and key naming — keep the
		// lowercase types and the two spaces after PRIMARY KEY. ShaonPro.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(190) NOT NULL DEFAULT '',
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			cart LONGTEXT NULL,
			cart_hash VARCHAR(32) NOT NULL DEFAULT '',
			item_count INT NOT NULL DEFAULT 0,
			token VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			abandoned_at DATETIME NULL,
			cart_value DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT '',
			order_value DECIMAL(18,2) NOT NULL DEFAULT 0,
			closed_at DATETIME NULL,
			last_error VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY token (token),
			KEY email (email),
			KEY status_updated (status, updated_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the table. Used by uninstall.php.
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * @return string Current time, UTC, MySQL format.
	 */
	protected static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/* ─── Cron ─────────────────────────────────────────────────────── */

	/**
	 * Register the five-minute interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (EmailSendX)', 'emailsendx-sync' ),
			);
		}
		return $schedules;
	}

	/**
	 * Keep the scan scheduled exactly while the feature is active.
	 */
	public static function ensure_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( self::is_active() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Remove every scheduled event. Called on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_unschedule_hook( self::PURCHASE_HOOK );
	}

	/* ─── Shopper identity ─────────────────────────────────────────── */

	/**
	 * @return WC_Session|null The shopper's WooCommerce session, if any.
	 */
	protected static function session() {
		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->session ) ) {
			return null;
		}
		return WC()->session;
	}

	/**
	 * One row per shopper: `u<user id>` for a logged-in customer (the
	 * same row on every device), `g<session id>` for a guest.
	 *
	 * @return string '' when there is no session to key on.
	 */
	protected static function session_key() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'u' . $user_id;
		}
		$session = self::session();
		if ( ! $session || ! method_exists( $session, 'get_customer_id' ) ) {
			return '';
		}
		$id = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $session->get_customer_id() );
		return '' === $id ? '' : substr( 'g' . $id, 0, 64 );
	}

	/**
	 * The shopper's choice about reminders: '', 'yes' or 'no'.
	 *
	 * @return string
	 */
	protected static function current_consent() {
		$value   = '';
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$value = (string) get_user_meta( $user_id, self::USER_META_CONSENT, true );
		}
		if ( '' === $value ) {
			$session = self::session();
			$value   = $session ? (string) $session->get( self::SESSION_CONSENT, '' ) : '';
		}
		return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
	}

	/**
	 * Remember the shopper's choice — on the account for a customer, in
	 * the session for a guest.
	 *
	 * @param string $consent 'yes' or 'no'.
	 */
	protected static function store_consent( $consent ) {
		if ( ! in_array( $consent, array( 'yes', 'no' ), true ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_META_CONSENT, $consent );
		}
		$session = self::session();
		if ( $session ) {
			$session->set( self::SESSION_CONSENT, $consent );
		}
	}

	/**
	 * Remember the email (and name) typed at checkout.
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return bool Whether a valid email was stored.
	 */
	protected static function store_details( $email, $first = '', $last = '' ) {
		$session = self::session();
		$email   = strtolower( sanitize_email( (string) $email ) );
		if ( ! $session || '' === $email || ! is_email( $email ) ) {
			return false;
		}
		$session->set( self::SESSION_EMAIL, $email );
		$first = self::clip( sanitize_text_field( (string) $first ), 100 );
		$last  = self::clip( sanitize_text_field( (string) $last ), 100 );
		if ( '' !== $first ) {
			$session->set( self::SESSION_FIRST, $first );
		}
		if ( '' !== $last ) {
			$session->set( self::SESSION_LAST, $last );
		}
		self::$dirty = true;
		return true;
	}

	/**
	 * The shopper's email, name and account, from the best source there is:
	 * what they typed at checkout, then their WooCommerce customer record,
	 * then their WordPress account.
	 *
	 * @return array{email:string,first:string,last:string,user_id:int}
	 */
	protected static function current_shopper() {
		$session = self::session();
		$user_id = (int) get_current_user_id();

		$email = $session ? (string) $session->get( self::SESSION_EMAIL, '' ) : '';
		$first = $session ? (string) $session->get( self::SESSION_FIRST, '' ) : '';
		$last  = $session ? (string) $session->get( self::SESSION_LAST, '' ) : '';

		$customer = ( function_exists( 'WC' ) && WC() && ! empty( WC()->customer ) ) ? WC()->customer : null;
		if ( $customer ) {
			if ( '' === $email ) {
				$email = (string) $customer->get_billing_email();
			}
			if ( '' === $first ) {
				$first = (string) $customer->get_billing_first_name();
			}
			if ( '' === $last ) {
				$last = (string) $customer->get_billing_last_name();
			}
		}

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				if ( '' === $email ) {
					$email = (string) $user->user_email;
				}
				if ( '' === $first ) {
					$first = (string) get_user_meta( $user_id, 'first_name', true );
				}
				if ( '' === $last ) {
					$last = (string) get_user_meta( $user_id, 'last_name', true );
				}
			}
		}

		$email = strtolower( sanitize_email( $email ) );
		return array(
			'email'   => is_email( $email ) ? $email : '',
			'first'   => self::clip( $first, 100 ),
			'last'    => self::clip( $last, 100 ),
			'user_id' => $user_id,
		);
	}

	/* ─── Capturing the cart ───────────────────────────────────────── */

	/**
	 * A cart hook fired — write the row at shutdown.
	 */
	public static function mark_dirty() {
		self::$dirty = true;
	}

	/**
	 * Classic checkout posts the whole form when totals refresh.
	 *
	 * @param string $post_data URL-encoded checkout form.
	 */
	public static function on_order_review( $post_data ) {
		$fields = array();
		parse_str( (string) $post_data, $fields );
		if ( empty( $fields['billing_email'] ) ) {
			return;
		}
		self::store_details(
			wp_unslash( $fields['billing_email'] ),
			isset( $fields['billing_first_name'] ) ? wp_unslash( $fields['billing_first_name'] ) : '',
			isset( $fields['billing_last_name'] ) ? wp_unslash( $fields['billing_last_name'] ) : ''
		);
	}

	/**
	 * Block checkout syncs the customer to the server as the shopper types.
	 *
	 * @param WC_Customer $customer Customer being updated.
	 */
	public static function on_store_api_customer( $customer ) {
		if ( ! is_object( $customer ) || ! method_exists( $customer, 'get_billing_email' ) ) {
			return;
		}
		self::store_details(
			$customer->get_billing_email(),
			$customer->get_billing_first_name(),
			$customer->get_billing_last_name()
		);
	}

	/**
	 * The checkout script: shows the reminder notice or consent box under
	 * the email field and reports the email once it is typed.
	 */
	public static function enqueue_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}
		$mode = self::consent_mode();
		if ( 'customers' === $mode ) {
			return; // Logged-in customers only: nothing to ask, nothing to capture.
		}

		wp_enqueue_script(
			'emailsendx-cart-capture',
			EMAILSENDX_SYNC_URL . 'assets/js/cart-capture.js',
			array(),
			EMAILSENDX_SYNC_VERSION,
			true
		);
		wp_localize_script(
			'emailsendx-cart-capture',
			'emailsendxCart',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_CAPTURE ),
				'mode'    => $mode,
				'consent' => self::current_consent(),
				'text'    => self::consent_texts(),
			)
		);
	}

	/**
	 * Wording shown at checkout. Filterable so a shop can match its tone
	 * or language: `emailsendx_cart_consent_texts`.
	 *
	 * @return array{notice:string,decline:string,declined:string,checkbox:string}
	 */
	public static function consent_texts() {
		$texts = array(
			'notice'   => __( 'We\'ll email you a reminder if you leave items in your cart.', 'emailsendx-sync' ),
			'decline'  => __( 'No thanks', 'emailsendx-sync' ),
			'declined' => __( 'OK — we won\'t send you cart reminders.', 'emailsendx-sync' ),
			'checkbox' => __( 'Email me a reminder if I leave items in my cart.', 'emailsendx-sync' ),
		);
		$filtered = apply_filters( 'emailsendx_cart_consent_texts', $texts );
		return is_array( $filtered ) ? array_merge( $texts, array_map( 'strval', $filtered ) ) : $texts;
	}

	/**
	 * AJAX: the checkout script reports the email and/or the shopper's
	 * choice. Expected POST: nonce, email?, first_name?, last_name?, consent?
	 */
	public static function ajax_capture() {
		if ( ! check_ajax_referer( self::NONCE_CAPTURE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'bad_nonce' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$consent = isset( $_POST['consent'] ) ? sanitize_key( wp_unslash( $_POST['consent'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$first   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( in_array( $consent, array( 'yes', 'no' ), true ) ) {
			self::store_consent( $consent );
		}
		if ( '' !== $email ) {
			self::store_details( $email, $first, $last );
		}

		self::$dirty = true;
		self::persist_if_dirty();

		wp_send_json_success( array( 'tracking' => self::can_track( self::consent_mode(), self::current_consent(), get_current_user_id() ) ) );
	}

	/**
	 * Write (or remove) this shopper's row. Runs once per request.
	 */
	public static function persist_if_dirty() {
		if ( ! self::$dirty ) {
			return;
		}
		self::$dirty = false;

		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->cart ) ) {
			return;
		}
		$key = self::session_key();
		if ( '' === $key ) {
			return;
		}

		global $wpdb;
		$table   = self::table_name();
		$shopper = self::current_shopper();
		$consent = self::current_consent();
		$allowed = self::can_track( self::consent_mode(), $consent, $shopper['user_id'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $key ), ARRAY_A );

		// Nothing to recover, or not allowed to: keep no open row. Rows that
		// already ended in an order stay for the 30-day stats.
		if ( WC()->cart->is_empty() || ! $allowed || '' === $shopper['email'] ) {
			if ( $row && in_array( $row['status'], array( 'active', 'abandoned', 'failed', 'skipped' ), true ) ) {
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			}
			if ( ! $allowed && '' !== $shopper['email'] ) {
				// A shopper who declined: forget carts saved under other sessions too.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = %s AND status IN ('active','abandoned','failed','skipped')", $shopper['email'] ) );
			}
			return;
		}

		$snapshot = self::snapshot_cart( WC()->cart );
		if ( empty( $snapshot['items'] ) ) {
			return;
		}
		$hash = md5( (string) wp_json_encode( array( $snapshot['items'], $snapshot['coupons'] ) ) );
		$now  = self::now();

		// One open cart per person. A shopper who comes back in another
		// browser (without using the emailed link) has no row under this
		// session yet, but may have an open one under their email: carry
		// that row over instead of starting a second one, so they are
		// reminded once and the emailed link shows their newest cart.
		if ( ! $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$open = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND status IN ('active','abandoned','failed','skipped') ORDER BY updated_at DESC LIMIT 1", $shopper['email'] ), ARRAY_A );
			if ( $open && false !== $wpdb->update( $table, array( 'session_key' => $key ), array( 'id' => (int) $open['id'] ) ) ) {
				$row = $open;
			}
		}

		$data = array(
			'user_id'    => $shopper['user_id'],
			'email'      => $shopper['email'],
			'first_name' => $shopper['first'],
			'last_name'  => $shopper['last'],
			'cart'       => wp_json_encode( $snapshot ),
			'cart_hash'  => $hash,
			'item_count' => (int) $snapshot['count'],
			'cart_value' => (float) $snapshot['value'],
			'currency'   => self::clip( $snapshot['currency'], 8 ),
			'updated_at' => $now,
		);

		if ( ! $row || in_array( $row['status'], array( 'ordered', 'recovered' ), true ) ) {
			// First cart, or a new cart after an order: start over.
			$data['token']        = self::new_token();
			$data['status']       = 'active';
			$data['attempts']     = 0;
			$data['order_id']     = 0;
			$data['order_value']  = 0;
			$data['last_error']   = '';
			$data['created_at']   = $now;
			$data['abandoned_at'] = null;
			$data['closed_at']    = null;
			if ( $row ) {
				$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
			} else {
				// Checkout fires several requests at once (its own customer
				// sync plus our script), and each can get here before any has
				// written. The unique session key makes the losers update the
				// winner's row instead of failing — without touching its
				// token, status or start time.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table}
							(session_key, user_id, email, first_name, last_name, cart, cart_hash, item_count, cart_value, currency, token, status, attempts, order_id, created_at, updated_at)
						VALUES (%s, %d, %s, %s, %s, %s, %s, %d, %f, %s, %s, 'active', 0, 0, %s, %s)
						ON DUPLICATE KEY UPDATE
							user_id = VALUES(user_id),
							email = VALUES(email),
							first_name = IF(VALUES(first_name) = '', first_name, VALUES(first_name)),
							last_name = IF(VALUES(last_name) = '', last_name, VALUES(last_name)),
							cart = VALUES(cart),
							cart_hash = VALUES(cart_hash),
							item_count = VALUES(item_count),
							cart_value = VALUES(cart_value),
							currency = VALUES(currency),
							updated_at = VALUES(updated_at)",
						$key,
						$data['user_id'],
						$data['email'],
						$data['first_name'],
						$data['last_name'],
						$data['cart'],
						$data['cart_hash'],
						$data['item_count'],
						$data['cart_value'],
						$data['currency'],
						$data['token'],
						$now,
						$now
					)
				);
			}
		} else {
			// A slower request must not blank a name a faster one just saved.
			if ( '' === $data['first_name'] ) {
				unset( $data['first_name'] );
			}
			if ( '' === $data['last_name'] ) {
				unset( $data['last_name'] );
			}
			$changed = $hash !== $row['cart_hash'] || $shopper['email'] !== $row['email'];
			if ( ! $changed ) {
				// Same cart, same person (a page reload, a restore-link click):
				// not activity worth restarting the clock for.
				unset( $data['updated_at'] );
			} elseif ( 'active' !== $row['status'] ) {
				// They came back and changed the cart after a reminder (or a
				// failed send): watch it again. The emailed link keeps working.
				$data['status']     = 'active';
				$data['attempts']   = 0;
				$data['last_error'] = '';
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
		}

		// Any other open row for this person is now a leftover.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = %s AND session_key <> %s AND status IN ('active','abandoned','failed','skipped')", $shopper['email'], $key ) );
	}

	/**
	 * The cart as plain data: enough to rebuild it and to show it in an
	 * email. Prices are display strings in the shop's own format.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array{items:array,coupons:array,total:string,value:float,currency:string,count:int}
	 */
	protected static function snapshot_cart( $cart ) {
		$items    = array();
		$count    = 0;
		$incl_tax = method_exists( $cart, 'display_prices_including_tax' ) && $cart->display_prices_including_tax();

		foreach ( $cart->get_cart() as $line ) {
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
				continue;
			}
			$qty    = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			$amount = isset( $line['line_subtotal'] ) ? (float) $line['line_subtotal'] : 0.0;
			if ( $incl_tax && isset( $line['line_subtotal_tax'] ) ) {
				$amount += (float) $line['line_subtotal_tax'];
			}
			$image_id = (int) $product->get_image_id();
			$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			$count   += $qty;

			$items[] = array(
				'product_id'   => isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
				'variation_id' => isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0,
				'variation'    => isset( $line['variation'] ) && is_array( $line['variation'] ) ? array_map( 'strval', $line['variation'] ) : array(),
				'qty'          => $qty,
				'name'         => self::clip( wp_strip_all_tags( $product->get_name() ), 200 ),
				'price'        => self::format_money( $amount ),
				'url'          => (string) $product->get_permalink(),
				'image_url'    => $image ? (string) $image : '',
			);
		}

		$total = (float) $cart->get_total( 'edit' );
		return array(
			'items'    => $items,
			'coupons'  => array_values( array_map( 'strval', (array) $cart->get_applied_coupons() ) ),
			'total'    => self::format_money( $total ),
			'value'    => round( $total, 2 ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'count'    => $count,
		);
	}

	/**
	 * An amount as the shop displays it, as plain text ("$24.00").
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	protected static function format_money( $amount ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/* ─── Pure helpers (covered by tools/tests) ────────────────────── */

	/**
	 * @return string A new 32-character restore token.
	 */
	protected static function new_token() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Restore tokens are exactly 32 letters and digits.
	 *
	 * @param mixed $token Candidate.
	 * @return bool
	 */
	public static function is_valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $token );
	}

	/**
	 * Cut a string to a maximum length (multibyte-safe when available).
	 *
	 * @param string $value Text.
	 * @param int    $max   Maximum characters.
	 * @return string
	 */
	public static function clip( $value, $max ) {
		$value = trim( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * The `data` sent with `cart_abandoned`. EmailSendX exposes every
	 * scalar as {{event.<key>}} and renders `items` with
	 * {{event.cart_items}}; only what an email needs is sent — product
	 * and variation ids stay on this site.
	 *
	 * @param array  $snapshot    Stored cart snapshot.
	 * @param string $restore_url The link that rebuilds the cart.
	 * @return array
	 */
	public static function build_cart_event( $snapshot, $restore_url ) {
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$source   = isset( $snapshot['items'] ) && is_array( $snapshot['items'] ) ? $snapshot['items'] : array();
		$items    = array();

		foreach ( array_slice( $source, 0, self::MAX_ITEMS ) as $line ) {
			if ( ! is_array( $line ) || empty( $line['name'] ) ) {
				continue;
			}
			$item = array(
				'name' => self::clip( $line['name'], 200 ),
				'qty'  => isset( $line['qty'] ) ? (int) $line['qty'] : 1,
			);
			foreach ( array( 'price', 'url', 'image_url' ) as $key ) {
				if ( ! empty( $line[ $key ] ) ) {
					$item[ $key ] = (string) $line[ $key ];
				}
			}
			$items[] = $item;
		}

		$event = array(
			'cart_url'   => (string) $restore_url,
			'cart_total' => isset( $snapshot['total'] ) ? (string) $snapshot['total'] : '',
			'cart_value' => isset( $snapshot['value'] ) ? (float) $snapshot['value'] : 0.0,
			'currency'   => isset( $snapshot['currency'] ) ? (string) $snapshot['currency'] : '',
			'item_count' => isset( $snapshot['count'] ) ? (int) $snapshot['count'] : count( $items ),
			'items'      => $items,
			'source'     => 'woocommerce',
		);

		// EmailSendX refuses payloads over 16 KB. Very long product links
		// could get there; drop items from the end until it fits (the
		// email shows the first ten anyway).
		while ( count( $event['items'] ) > 1 && strlen( (string) json_encode( $event ) ) > self::MAX_EVENT_BYTES ) {
			array_pop( $event['items'] );
		}
		return $event;
	}

	/**
	 * The contact row for the bulk upsert. Empty names are left out so an
	 * existing contact's name is never blanked.
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return array
	 */
	public static function build_contact( $email, $first = '', $last = '' ) {
		$contact = array(
			'email'         => strtolower( trim( (string) $email ) ),
			'consentSource' => self::CONSENT_SOURCE,
		);
		$first = self::clip( $first, 100 );
		$last  = self::clip( $last, 100 );
		if ( '' !== $first ) {
			$contact['firstName'] = $first;
		}
		if ( '' !== $last ) {
			$contact['lastName'] = $last;
		}
		return $contact;
	}

	/**
	 * The link in the reminder email.
	 *
	 * @param string $token Restore token.
	 * @return string
	 */
	public static function restore_url( $token ) {
		return add_query_arg( self::RESTORE_PARAM, rawurlencode( (string) $token ), home_url( '/' ) );
	}

	/* ─── Restoring a cart from the emailed link ───────────────────── */

	/**
	 * `?esx_cart=<token>`: rebuild the saved cart for whoever opens the
	 * link — on any device — and send them to checkout.
	 */
	public static function maybe_restore() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		if ( empty( $_GET[ self::RESTORE_PARAM ] ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->cart ) || empty( WC()->session ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::RESTORE_PARAM ] ) );

		$row = null;
		if ( self::is_valid_token( $token ) ) {
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s AND status IN ('active','abandoned')", $token ), ARRAY_A );
		}

		if ( ! $row ) {
			// Already bought, expired, or a mangled link: the shop's own cart
			// page is the least surprising place to land.
			wc_add_notice( __( 'That cart link is no longer available. Here is your current cart.', 'emailsendx-sync' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$session = WC()->session;
		if ( method_exists( $session, 'has_session' ) && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}

		$key      = self::session_key();
		$snapshot = json_decode( (string) $row['cart'], true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		// Rebuild unless this browser already holds this very cart.
		$same_cart = $key === $row['session_key'] && ! WC()->cart->is_empty();
		if ( ! $same_cart ) {
			WC()->cart->empty_cart( false );
			$lines = isset( $snapshot['items'] ) && is_array( $snapshot['items'] ) ? $snapshot['items'] : array();
			foreach ( $lines as $line ) {
				$product_id = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
				if ( $product_id <= 0 ) {
					continue;
				}
				try {
					WC()->cart->add_to_cart(
						$product_id,
						isset( $line['qty'] ) ? max( 1, (int) $line['qty'] ) : 1,
						isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0,
						isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : array()
					);
				} catch ( \Throwable $e ) {
					// A product that is gone or out of stock: restore the rest.
					continue;
				}
			}
			if ( ! empty( $snapshot['coupons'] ) && is_array( $snapshot['coupons'] ) && wc_coupons_enabled() ) {
				foreach ( $snapshot['coupons'] as $code ) {
					if ( ! WC()->cart->has_discount( (string) $code ) ) {
						WC()->cart->apply_coupon( (string) $code );
					}
				}
				// "Coupon applied" is noise on arrival; real problems show at checkout.
				wc_clear_notices();
			}
		}

		// Carry the shopper over so checkout is pre-filled and the cart
		// stays one row, now under this browser's session.
		$session->set( self::SESSION_EMAIL, (string) $row['email'] );
		if ( '' !== (string) $row['first_name'] ) {
			$session->set( self::SESSION_FIRST, (string) $row['first_name'] );
		}
		if ( '' !== (string) $row['last_name'] ) {
			$session->set( self::SESSION_LAST, (string) $row['last_name'] );
		}
		if ( ! empty( WC()->customer ) && '' === (string) WC()->customer->get_billing_email() ) {
			WC()->customer->set_billing_email( (string) $row['email'] );
			WC()->customer->save();
		}

		if ( '' !== $key && $key !== $row['session_key'] ) {
			global $wpdb;
			$table = self::table_name();
			$wpdb->delete( $table, array( 'session_key' => $key ), array( '%s' ) );
			$wpdb->update(
				$table,
				array(
					'session_key' => $key,
					'user_id'     => (int) get_current_user_id(),
				),
				array( 'id' => (int) $row['id'] )
			);
		}

		wp_safe_redirect( WC()->cart->is_empty() ? wc_get_cart_url() : wc_get_checkout_url() );
		exit;
	}

	/* ─── The scan: idle carts → cart_abandoned ────────────────────── */

	/**
	 * WP-Cron callback, every five minutes.
	 *
	 * @return array{sent:int,skipped:int,failed:int} Counts, for the log and tests.
	 */
	public static function run_scan() {
		$result = array(
			'sent'    => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		if ( ! self::is_active() || ! class_exists( 'EmailSendX_API' ) ) {
			return $result;
		}
		// One run at a time; the lock outlives a slow run by a little.
		if ( get_transient( 'emailsendx_sync_cart_lock' ) ) {
			return $result;
		}
		set_transient( 'emailsendx_sync_cart_lock', 1, 4 * MINUTE_IN_SECONDS );

		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::idle_minutes() * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND email <> '' AND updated_at <= %s ORDER BY updated_at ASC LIMIT %d", $cutoff, self::BATCH ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$outcome = self::report_abandoned( $row );
			if ( isset( $result[ $outcome ] ) ) {
				++$result[ $outcome ];
			}
		}

		// Retention: nothing is kept past 30 days.
		$expired = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s LIMIT 500", $expired ) );

		delete_transient( 'emailsendx_sync_cart_lock' );
		return $result;
	}

	/**
	 * Report one idle cart: add the shopper to the list, send the event.
	 *
	 * @param array $row Cart row.
	 * @return string 'sent', 'skipped' or 'failed'.
	 */
	protected static function report_abandoned( $row ) {
		global $wpdb;
		$table = self::table_name();
		$id    = (int) $row['id'];

		$snapshot = json_decode( (string) $row['cart'], true );
		if ( ! is_array( $snapshot ) || empty( $snapshot['items'] ) ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			return 'skipped';
		}

		// They bought since — through another browser, or an order made for
		// them in wp-admin. Never remind someone who already paid.
		if ( self::has_order_since( $row['email'], $row['created_at'] ) ) {
			$wpdb->update(
				$table,
				array(
					'status'      => 'ordered',
					'closed_at'   => self::now(),
					'session_key' => 'o' . $id . '-' . substr( (string) $row['session_key'], 0, 40 ),
				),
				array( 'id' => $id )
			);
			return 'skipped';
		}

		$api = EmailSendX_API::instance();

		$upsert = $api->bulk_upsert_contacts(
			array(
				'createListIfMissing' => true,
				'listName'            => self::LIST_NAME,
				'consentSource'       => self::CONSENT_SOURCE,
				'contacts'            => array( self::build_contact( $row['email'], $row['first_name'], $row['last_name'] ) ),
			)
		);
		if ( is_wp_error( $upsert ) ) {
			return self::note_failure( $row, 'cart.contact', $upsert->get_error_message() );
		}

		$sent = $api->send_event(
			$row['email'],
			self::EVENT_ABANDONED,
			self::build_cart_event( $snapshot, self::restore_url( $row['token'] ) ),
			true
		);
		if ( is_wp_error( $sent ) ) {
			$data    = $sent->get_error_data();
			$message = $sent->get_error_message();
			if ( is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'] ) {
				$message = __( 'EmailSendX did not accept the cart event (404). Abandoned cart recovery needs an up-to-date EmailSendX.', 'emailsendx-sync' );
			}
			return self::note_failure( $row, 'cart.event', $message );
		}

		// EmailSendX has no contact for this address: it refused to create
		// one (suppressed, unsubscribed from everything, plan limit). Not an
		// error, and not worth retrying.
		$matched = ! ( isset( $sent['data'] ) && is_array( $sent['data'] ) && array_key_exists( 'matched', $sent['data'] ) && false === $sent['data']['matched'] );
		if ( ! $matched ) {
			$wpdb->update( $table, array( 'status' => 'skipped' ), array( 'id' => $id ) );
			return 'skipped';
		}

		self::note_automations( $sent );

		$wpdb->update(
			$table,
			array(
				'status'       => 'abandoned',
				'abandoned_at' => self::now(),
				'last_error'   => '',
			),
			array( 'id' => $id )
		);
		return 'sent';
	}

	/**
	 * A send failed: count it, give up after MAX_ATTEMPTS, and put it in
	 * the Log tab.
	 *
	 * @param array  $row     Cart row.
	 * @param string $source  Log source.
	 * @param string $message Error message.
	 * @return string Always 'failed'.
	 */
	protected static function note_failure( $row, $source, $message ) {
		global $wpdb;
		$attempts = (int) $row['attempts'] + 1;
		$wpdb->update(
			self::table_name(),
			array(
				'attempts'   => $attempts,
				'status'     => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'active',
				'last_error' => self::clip( wp_strip_all_tags( (string) $message ), 255 ),
			),
			array( 'id' => (int) $row['id'] )
		);
		if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'record_error' ) ) {
			EmailSendX_Log::record_error(
				array(
					'source'  => $source,
					'message' => (string) $message,
				)
			);
		}
		return 'failed';
	}

	/**
	 * Whether this email has a paid (or awaiting-payment-confirmation)
	 * order placed after the cart was first saved.
	 *
	 * @param string $email   Billing email.
	 * @param string $since   UTC MySQL datetime.
	 * @return bool
	 */
	protected static function has_order_since( $email, $since ) {
		if ( ! function_exists( 'wc_get_orders' ) || '' === (string) $email ) {
			return false;
		}
		$timestamp = strtotime( (string) $since . ' UTC' );
		$orders    = wc_get_orders(
			array(
				'billing_email' => (string) $email,
				'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'date_created'  => '>=' . ( $timestamp ? $timestamp : 0 ),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		return ! empty( $orders );
	}

	/* ─── Orders → purchase_completed ──────────────────────────────── */

	/**
	 * An order was created at checkout but may still be unpaid (the shopper
	 * is at the payment provider). Restart the idle clock so a reminder
	 * cannot go out while they are paying.
	 */
	public static function on_order_created() {
		$key = self::session_key();
		if ( '' === $key ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'updated_at' => self::now() ),
			array(
				'session_key' => $key,
				'status'      => 'active',
			)
		);
	}

	/**
	 * An order reached processing / completed / on-hold: close the
	 * shopper's cart and queue `purchase_completed`. The API call runs
	 * from WP-Cron so checkout never waits on it.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_order_paid( $order_id ) {
		$order_id = (int) $order_id;
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return;
		}

		self::close_carts_for_order( $order );

		if ( $order->get_meta( self::ORDER_META_SENT ) ) {
			return; // processing → completed: already reported.
		}
		if ( ! wp_next_scheduled( self::PURCHASE_HOOK, array( $order_id ) ) ) {
			wp_schedule_single_event( time(), self::PURCHASE_HOOK, array( $order_id ) );
		}
	}

	/**
	 * Mark the buyer's open carts as ordered — `recovered` when a reminder
	 * had gone out first.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function close_carts_for_order( $order ) {
		global $wpdb;
		$table   = self::table_name();
		$email   = strtolower( (string) $order->get_billing_email() );
		$user_id = (int) $order->get_user_id();
		$key     = self::session_key();

		$where = array();
		$args  = array( (int) $order->get_id(), round( (float) $order->get_total(), 2 ), self::now() );
		if ( '' !== $email ) {
			$where[] = 'email = %s';
			$args[]  = $email;
		}
		if ( $user_id > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}
		if ( '' !== $key && ! is_admin() ) {
			$where[] = 'session_key = %s';
			$args[]  = $key;
		}
		if ( empty( $where ) ) {
			return;
		}

		// The finished row gives up its session key (it becomes
		// `o<id>-<old key>`), so the shopper's next cart gets a row of its
		// own and this one stays in the 30-day figures as an order.
		$sql = "UPDATE {$table}
			SET status = IF(abandoned_at IS NULL, 'ordered', 'recovered'),
				order_id = %d,
				order_value = %f,
				closed_at = %s,
				session_key = CONCAT('o', id, '-', LEFT(session_key, 40))
			WHERE (" . implode( ' OR ', $where ) . ") AND status IN ('active','abandoned','failed','skipped')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * WP-Cron callback: tell EmailSendX the order happened. Sent for every
	 * paid order; EmailSendX ignores it when the buyer is not a contact,
	 * so no contact is ever created by a purchase.
	 *
	 * @param int $order_id Order id.
	 */
	public static function send_purchase( $order_id ) {
		$order_id = (int) $order_id;
		if ( ! self::is_active() || ! class_exists( 'EmailSendX_API' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::ORDER_META_SENT ) ) {
			return;
		}
		$email = strtolower( (string) $order->get_billing_email() );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$sent = EmailSendX_API::instance()->send_event( $email, self::EVENT_PURCHASE, self::build_order_event( $order ), true );

		if ( is_wp_error( $sent ) ) {
			$tries = (int) $order->get_meta( self::ORDER_META_TRIES ) + 1;
			$order->update_meta_data( self::ORDER_META_TRIES, $tries );
			$order->save();
			if ( $tries < self::MAX_ATTEMPTS ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::PURCHASE_HOOK, array( $order_id ) );
			}
			if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'record_error' ) ) {
				EmailSendX_Log::record_error(
					array(
						'source'  => 'cart.purchase',
						'message' => $sent->get_error_message(),
					)
				);
			}
			return;
		}

		$order->update_meta_data( self::ORDER_META_SENT, time() );
		$order->save();
	}

	/**
	 * The `data` sent with `purchase_completed`.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected static function build_order_event( $order ) {
		$items = array();
		foreach ( $order->get_items() as $line ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
			$items[] = array(
				'name'  => self::clip( wp_strip_all_tags( $line->get_name() ), 200 ),
				'qty'   => (int) $line->get_quantity(),
				'price' => self::format_order_money( (float) $line->get_total(), $order ),
			);
		}
		return array(
			'order_id'     => (int) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'order_total'  => self::format_order_money( (float) $order->get_total(), $order ),
			'order_value'  => round( (float) $order->get_total(), 2 ),
			'currency'     => (string) $order->get_currency(),
			'item_count'   => (int) $order->get_item_count(),
			'items'        => $items,
			'source'       => 'woocommerce',
		);
	}

	/**
	 * An order amount in the order's own currency, as plain text.
	 *
	 * @param float    $amount Amount.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	protected static function format_order_money( $amount, $order ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}
		$html = wc_price( $amount, array( 'currency' => $order->get_currency() ) );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/* ─── Carts tab ────────────────────────────────────────────────── */

	/**
	 * Remember what EmailSendX said about the last cart event: how many
	 * active automations were waiting for it. The Carts tab uses it to
	 * tick "reminder emails are on" without another API call.
	 *
	 * @param mixed $response send_event() result.
	 * @param bool  $is_test  True for "Send a test cart".
	 */
	protected static function note_automations( $response, $is_test = false ) {
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) || ! array_key_exists( 'matchingAutomations', $response['data'] ) ) {
			return;
		}
		$state = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$count = (int) $response['data']['matchingAutomations'];
		$same  = isset( $state['cart_automations'] ) && (int) $state['cart_automations'] === $count;
		if ( $same && ! $is_test ) {
			return; // Nothing new to store — keep the scan free of option writes.
		}
		$state['cart_automations'] = $count;
		if ( $is_test ) {
			$state['cart_test_at'] = time();
		}
		update_option( EMAILSENDX_SYNC_OPT_STATE, $state );
	}

	/**
	 * How each stored status reads to a merchant: label, pill colour and a
	 * one-line explanation.
	 *
	 * @return array<string,array{label:string,tone:string,help:string}>
	 */
	public static function status_labels() {
		return array(
			'active'    => array(
				'label' => __( 'Shopping', 'emailsendx-sync' ),
				'tone'  => 'info',
				'help'  => __( 'The cart is saved and still being watched. No reminder has been sent.', 'emailsendx-sync' ),
			),
			'abandoned' => array(
				'label' => __( 'Abandoned', 'emailsendx-sync' ),
				'tone'  => 'warn',
				'help'  => __( 'The shopper left. EmailSendX has the cart and is sending your reminder emails.', 'emailsendx-sync' ),
			),
			'recovered' => array(
				'label' => __( 'Recovered', 'emailsendx-sync' ),
				'tone'  => 'ok',
				'help'  => __( 'The shopper ordered after a reminder was sent.', 'emailsendx-sync' ),
			),
			'ordered'   => array(
				'label' => __( 'Ordered', 'emailsendx-sync' ),
				'tone'  => 'muted',
				'help'  => __( 'The shopper ordered before any reminder was needed.', 'emailsendx-sync' ),
			),
			'skipped'   => array(
				'label' => __( 'Not emailed', 'emailsendx-sync' ),
				'tone'  => 'muted',
				'help'  => __( 'EmailSendX would not email this address — it is unsubscribed, suppressed, or your contact limit is reached.', 'emailsendx-sync' ),
			),
			'failed'    => array(
				'label' => __( 'Could not send', 'emailsendx-sync' ),
				'tone'  => 'error',
				'help'  => __( 'The cart could not be sent to EmailSendX after three tries. See the Log tab.', 'emailsendx-sync' ),
			),
		);
	}

	/**
	 * The filters above the carts table, and the stored statuses each one
	 * shows.
	 *
	 * @return array<string,array{label:string,statuses:string[]}>
	 */
	public static function filter_groups() {
		return array(
			'all'       => array(
				'label'    => __( 'All', 'emailsendx-sync' ),
				'statuses' => array(),
			),
			'abandoned' => array(
				'label'    => __( 'Abandoned', 'emailsendx-sync' ),
				'statuses' => array( 'abandoned' ),
			),
			'recovered' => array(
				'label'    => __( 'Recovered', 'emailsendx-sync' ),
				'statuses' => array( 'recovered' ),
			),
			'active'    => array(
				'label'    => __( 'Shopping', 'emailsendx-sync' ),
				'statuses' => array( 'active' ),
			),
			'ordered'   => array(
				'label'    => __( 'Ordered', 'emailsendx-sync' ),
				'statuses' => array( 'ordered' ),
			),
			'unsent'    => array(
				'label'    => __( 'Not sent', 'emailsendx-sync' ),
				'statuses' => array( 'skipped', 'failed' ),
			),
		);
	}

	/**
	 * A valid filter slug; anything else is 'all'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_filter( $value ) {
		$groups = self::filter_groups();
		return is_string( $value ) && isset( $groups[ $value ] ) ? $value : 'all';
	}

	/**
	 * A valid period for the Carts tab: 7 or 30 days. Carts are kept 30
	 * days, so 30 is everything.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_range( $value ) {
		return 7 === (int) $value ? 7 : self::RETENTION_DAYS;
	}

	/**
	 * Share of abandoned carts that ended in an order, as a whole percent.
	 *
	 * @param int $abandoned Carts a reminder went out for (recovered ones included).
	 * @param int $recovered Of those, how many ordered.
	 * @return int 0–100.
	 */
	public static function recovery_rate( $abandoned, $recovered ) {
		$abandoned = (int) $abandoned;
		$recovered = (int) $recovered;
		if ( $abandoned <= 0 || $recovered <= 0 ) {
			return 0;
		}
		return (int) min( 100, round( $recovered / $abandoned * 100 ) );
	}

	/**
	 * An amount for the admin screens, in the given currency, as plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO code; empty for the shop's currency.
	 * @return string
	 */
	public static function admin_money( $amount, $currency = '' ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return trim( number_format( (float) $amount, 2 ) . ' ' . $currency );
		}
		$args = '' !== (string) $currency ? array( 'currency' => (string) $currency ) : array();
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $args ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Bring an existing carts table up to this version. The table is
	 * normally upgraded while the feature is on; this covers a shop that
	 * switched it off before updating and then opens the Carts tab.
	 */
	public static function ensure_table_current() {
		if ( self::table_ready() ) {
			self::maybe_install();
		}
	}

	/**
	 * The figures at the top of the Carts tab.
	 *
	 * @param int $days 7 or 30.
	 * @return array{tracked:int,abandoned:int,recovered:int,revenue:float,open_value:float,rate:int}
	 */
	public static function get_overview( $days = 30 ) {
		$out = array(
			'tracked'    => 0,
			'abandoned'  => 0,
			'recovered'  => 0,
			'revenue'    => 0.0,
			'open_value' => 0.0,
			'rate'       => 0,
		);
		if ( ! self::table_ready() ) {
			return $out;
		}
		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::sanitize_range( $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS tracked, SUM(abandoned_at IS NOT NULL) AS abandoned, SUM(status = 'recovered') AS recovered, SUM(IF(status = 'recovered', order_value, 0)) AS revenue, SUM(IF(status = 'abandoned', cart_value, 0)) AS open_value FROM {$table} WHERE updated_at >= %s", $cutoff ), ARRAY_A );
		if ( is_array( $row ) ) {
			$out['tracked']    = (int) $row['tracked'];
			$out['abandoned']  = (int) $row['abandoned'];
			$out['recovered']  = (int) $row['recovered'];
			$out['revenue']    = (float) $row['revenue'];
			$out['open_value'] = (float) $row['open_value'];
			$out['rate']       = self::recovery_rate( $out['abandoned'], $out['recovered'] );
		}
		return $out;
	}

	/**
	 * One page of carts for the table, newest activity first.
	 *
	 * @param array $args { filter, search, page, days }.
	 * @return array{rows:array,total:int,pages:int,page:int,counts:array<string,int>}
	 */
	public static function query_carts( $args = array() ) {
		$out = array(
			'rows'   => array(),
			'total'  => 0,
			'pages'  => 1,
			'page'   => 1,
			'counts' => array(),
		);
		if ( ! self::table_ready() ) {
			return $out;
		}
		global $wpdb;
		$table  = self::table_name();
		$groups = self::filter_groups();
		$filter = self::sanitize_filter( isset( $args['filter'] ) ? $args['filter'] : 'all' );
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$page   = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::sanitize_range( isset( $args['days'] ) ? $args['days'] : 30 ) * DAY_IN_SECONDS );

		$where  = array( 'updated_at >= %s' );
		$params = array( $cutoff );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$base = implode( ' AND ', $where );

		// Counts per status for the filter pills (same period and search).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$by_status = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE {$base} GROUP BY status", $params ), ARRAY_A );
		$per       = array();
		foreach ( (array) $by_status as $r ) {
			$per[ (string) $r['status'] ] = (int) $r['n'];
		}
		foreach ( $groups as $slug => $group ) {
			$out['counts'][ $slug ] = empty( $group['statuses'] )
				? array_sum( $per )
				: array_sum( array_intersect_key( $per, array_flip( $group['statuses'] ) ) );
		}

		if ( ! empty( $groups[ $filter ]['statuses'] ) ) {
			$marks  = implode( ',', array_fill( 0, count( $groups[ $filter ]['statuses'] ), '%s' ) );
			$base  .= " AND status IN ({$marks})";
			$params = array_merge( $params, $groups[ $filter ]['statuses'] );
		}

		$out['total'] = (int) $out['counts'][ $filter ];
		$out['pages'] = max( 1, (int) ceil( $out['total'] / self::PER_PAGE ) );
		$out['page']  = min( $page, $out['pages'] );

		$params[] = self::PER_PAGE;
		$params[] = ( $out['page'] - 1 ) * self::PER_PAGE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$base} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );
		$out['rows'] = is_array( $rows ) ? $rows : array();
		return $out;
	}

	/**
	 * One cart, with its snapshot decoded into `items`, `coupons`, `total`.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_cart( $id ) {
		if ( ! self::table_ready() || (int) $id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A cart's saved contents as plain arrays, safe against a missing or
	 * broken snapshot.
	 *
	 * @param array $row Cart row.
	 * @return array{items:array,coupons:array,total:string}
	 */
	public static function cart_contents( $row ) {
		$snapshot = json_decode( isset( $row['cart'] ) ? (string) $row['cart'] : '', true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$items    = array();
		foreach ( isset( $snapshot['items'] ) && is_array( $snapshot['items'] ) ? $snapshot['items'] : array() as $line ) {
			if ( ! is_array( $line ) || empty( $line['name'] ) ) {
				continue;
			}
			$items[] = array(
				'name'      => (string) $line['name'],
				'qty'       => isset( $line['qty'] ) ? max( 1, (int) $line['qty'] ) : 1,
				'price'     => isset( $line['price'] ) ? (string) $line['price'] : '',
				'url'       => isset( $line['url'] ) ? (string) $line['url'] : '',
				'image_url' => isset( $line['image_url'] ) ? (string) $line['image_url'] : '',
			);
		}
		return array(
			'items'   => $items,
			'coupons' => isset( $snapshot['coupons'] ) && is_array( $snapshot['coupons'] ) ? array_values( array_map( 'strval', $snapshot['coupons'] ) ) : array(),
			'total'   => isset( $snapshot['total'] ) ? (string) $snapshot['total'] : '',
		);
	}

	/**
	 * "Blue T-shirt + 2 more" — the cart in a few words, for the table.
	 *
	 * @param array $items Items from cart_contents().
	 * @return string
	 */
	public static function cart_summary( $items ) {
		$items = is_array( $items ) ? array_values( $items ) : array();
		if ( empty( $items ) ) {
			return '';
		}
		$first = self::clip( isset( $items[0]['name'] ) ? $items[0]['name'] : '', 60 );
		$more  = count( $items ) - 1;
		if ( $more <= 0 ) {
			return $first;
		}
		/* translators: 1: first product name, 2: number of other products */
		return sprintf( _n( '%1$s + %2$d more', '%1$s + %2$d more', $more, 'emailsendx-sync' ), $first, $more );
	}

	/**
	 * What happened to a cart, oldest first. Times are UTC MySQL strings;
	 * an entry with `pending` has not happened yet.
	 *
	 * @param array $row          Cart row.
	 * @param int   $idle_minutes Minutes before an untouched cart is abandoned.
	 * @return array<int,array{key:string,time:string,tone:string,pending:bool}>
	 */
	public static function timeline( $row, $idle_minutes = 60 ) {
		$status  = isset( $row['status'] ) ? (string) $row['status'] : 'active';
		$created = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		$updated = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
		$left    = ! empty( $row['abandoned_at'] ) ? (string) $row['abandoned_at'] : '';
		$closed  = ! empty( $row['closed_at'] ) ? (string) $row['closed_at'] : '';

		$steps   = array();
		$steps[] = array(
			'key'     => 'saved',
			'time'    => $created,
			'tone'    => 'info',
			'pending' => false,
		);
		if ( '' !== $updated && $updated !== $created && ( '' === $left || $updated < $left ) ) {
			$steps[] = array(
				'key'     => 'changed',
				'time'    => $updated,
				'tone'    => 'info',
				'pending' => false,
			);
		}
		if ( '' !== $left ) {
			$steps[] = array(
				'key'     => 'abandoned',
				'time'    => $left,
				'tone'    => 'warn',
				'pending' => false,
			);
			if ( '' !== $updated && $updated > $left && 'abandoned' === $status ) {
				$steps[] = array(
					'key'     => 'returned',
					'time'    => $updated,
					'tone'    => 'info',
					'pending' => false,
				);
			}
		}
		if ( 'recovered' === $status || 'ordered' === $status ) {
			$steps[] = array(
				'key'     => $status,
				'time'    => $closed,
				'tone'    => 'recovered' === $status ? 'ok' : 'muted',
				'pending' => false,
			);
		} elseif ( 'skipped' === $status || 'failed' === $status ) {
			$steps[] = array(
				'key'     => $status,
				'time'    => '',
				'tone'    => 'failed' === $status ? 'error' : 'muted',
				'pending' => false,
			);
		} elseif ( 'active' === $status && '' !== $updated ) {
			$due     = strtotime( $updated . ' UTC' );
			$steps[] = array(
				'key'     => 'due',
				'time'    => $due ? gmdate( 'Y-m-d H:i:s', $due + max( 1, (int) $idle_minutes ) * 60 ) : '',
				'tone'    => 'muted',
				'pending' => true,
			);
		}
		return $steps;
	}

	/**
	 * The setup checklist at the top of the Carts tab.
	 *
	 * @return array<int,array{key:string,done:bool}>
	 */
	public static function setup_steps() {
		$state = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		return self::build_setup_steps(
			emailsendx_sync_is_configured(),
			self::is_enabled(),
			isset( $state['cart_automations'] ) ? (int) $state['cart_automations'] : -1,
			! empty( $state['cart_test_at'] )
		);
	}

	/**
	 * The checklist from plain facts. A step can only be done once the
	 * steps before it are.
	 *
	 * @param bool $connected   API key saved.
	 * @param bool $enabled     Cart recovery switched on.
	 * @param int  $automations Active automations EmailSendX last reported for the cart event; -1 = never asked.
	 * @param bool $tested      A test cart was sent successfully.
	 * @return array<int,array{key:string,done:bool}>
	 */
	public static function build_setup_steps( $connected, $enabled, $automations, $tested ) {
		$connected = (bool) $connected;
		$enabled   = $connected && (bool) $enabled;
		$emails    = $enabled && (int) $automations > 0;
		$tested    = $emails && (bool) $tested;
		return array(
			array(
				'key'  => 'connect',
				'done' => $connected,
			),
			array(
				'key'  => 'enable',
				'done' => $enabled,
			),
			array(
				'key'  => 'emails',
				'done' => $emails,
			),
			array(
				'key'  => 'test',
				'done' => $tested,
			),
		);
	}

	/**
	 * admin-post: delete one saved cart from the Carts tab.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_DELETE );

		$id      = isset( $_POST['cart_id'] ) ? (int) $_POST['cart_id'] : 0;
		$removed = 0;
		if ( $id > 0 && self::table_ready() ) {
			global $wpdb;
			$removed = (int) $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		}
		set_transient(
			self::TEST_TRANSIENT,
			array(
				'ok'      => $removed > 0,
				'message' => $removed > 0
					? __( 'Cart deleted. Reminders already started in EmailSendX are not stopped by this.', 'emailsendx-sync' )
					: __( 'That cart no longer exists.', 'emailsendx-sync' ),
			),
			MINUTE_IN_SECONDS
		);

		$back = ( class_exists( 'EmailSendX_Admin' ) && method_exists( 'EmailSendX_Admin', 'get_admin_url' ) )
			? EmailSendX_Admin::get_admin_url( 'carts' )
			: admin_url( 'admin.php?page=emailsendx-sync&tab=carts' );
		wp_safe_redirect( $back );
		exit;
	}

	/* ─── Shared figures ────────────────────────────────────────────── */

	/**
	 * Counts for the status line. Rows live 30 days, so the table itself
	 * is the 30-day window.
	 *
	 * @return array{tracked:int,abandoned:int,recovered:int}
	 */
	public static function get_stats() {
		global $wpdb;
		$stats = array(
			'tracked'   => 0,
			'abandoned' => 0,
			'recovered' => 0,
		);
		$state = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		if ( ! is_array( $state ) || empty( $state['cart_db'] ) ) {
			return $stats; // Table not created yet.
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) AS tracked, SUM(abandoned_at IS NOT NULL) AS abandoned, SUM(status = 'recovered') AS recovered FROM {$table}", ARRAY_A );
		if ( is_array( $row ) ) {
			foreach ( $stats as $key => $unused ) {
				$stats[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
			}
		}
		return $stats;
	}

	/**
	 * Where the merchant builds the email sequence: the workspace's
	 * Automations page. Falls back to the EmailSendX home page when the
	 * workspace cannot be read. Cached for 12 hours.
	 *
	 * @return string
	 */
	public static function sequences_url() {
		$s    = emailsendx_sync_get_settings();
		$base = isset( $s['api_base'] ) ? untrailingslashit( (string) $s['api_base'] ) : '';
		if ( '' === $base ) {
			return 'https://emailsendx.com';
		}
		$cache_key = 'emailsendx_sync_ws_' . md5( $base . '|' . ( isset( $s['api_key'] ) ? $s['api_key'] : '' ) );
		$slug      = get_transient( $cache_key );
		if ( false === $slug ) {
			$slug = '';
			if ( emailsendx_sync_is_configured() && class_exists( 'EmailSendX_API' ) ) {
				$who = EmailSendX_API::instance()->whoami();
				if ( ! is_wp_error( $who ) && isset( $who['data']['workspace'] ) && is_array( $who['data']['workspace'] ) ) {
					$ws   = $who['data']['workspace'];
					$slug = ! empty( $ws['slug'] ) ? (string) $ws['slug'] : ( ! empty( $ws['id'] ) ? (string) $ws['id'] : '' );
				}
			}
			set_transient( $cache_key, $slug, 12 * HOUR_IN_SECONDS );
		}
		// `?setup=abandoned-cart` makes EmailSendX show its cart recovery
		// card (one-click reminder emails) even before the first cart arrives.
		return '' !== $slug ? $base . '/workspaces/' . rawurlencode( $slug ) . '/automations?setup=abandoned-cart' : $base;
	}

	/**
	 * admin-post: "Send a test cart". Adds the current admin to the
	 * Abandoned carts list and fires a sample `cart_abandoned` for them,
	 * so the merchant can see the real reminder arrive in their own inbox.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_TEST );

		$status = array(
			'ok'      => false,
			'message' => __( 'Connect to EmailSendX first.', 'emailsendx-sync' ),
		);

		$user  = wp_get_current_user();
		$email = $user ? strtolower( (string) $user->user_email ) : '';

		if ( emailsendx_sync_is_configured() && class_exists( 'EmailSendX_API' ) && is_email( $email ) ) {
			$api    = EmailSendX_API::instance();
			$upsert = $api->bulk_upsert_contacts(
				array(
					'createListIfMissing' => true,
					'listName'            => self::LIST_NAME,
					'consentSource'       => self::CONSENT_SOURCE,
					'contacts'            => array( self::build_contact( $email, (string) $user->first_name, (string) $user->last_name ) ),
				)
			);
			$sent = is_wp_error( $upsert )
				? $upsert
				: $api->send_event( $email, self::EVENT_ABANDONED, self::sample_event(), true );

			if ( is_wp_error( $sent ) ) {
				$status['message'] = sprintf(
					/* translators: %s: error message from EmailSendX */
					__( 'The test cart could not be sent: %s', 'emailsendx-sync' ),
					$sent->get_error_message()
				);
			} elseif ( isset( $sent['data']['matched'] ) && false === $sent['data']['matched'] ) {
				$status['message'] = sprintf(
					/* translators: %s: email address */
					__( 'EmailSendX would not add %s as a contact (it may be unsubscribed or suppressed), so no reminder will be sent.', 'emailsendx-sync' ),
					$email
				);
			} else {
				$count           = isset( $sent['data']['matchingAutomations'] ) ? (int) $sent['data']['matchingAutomations'] : 0;
				self::note_automations( $sent, true );
				$status['ok']    = true;
				$status['message'] = $count > 0
					? sprintf(
						/* translators: %s: email address */
						__( 'Test cart sent for %s. If your Abandoned Cart sequence is on, the first reminder arrives within a few minutes.', 'emailsendx-sync' ),
						$email
					)
					: sprintf(
						/* translators: %s: email address */
						__( 'Test cart sent for %s — but EmailSendX has no active automation waiting for cart events yet. Create the Abandoned Cart sequence and turn it on.', 'emailsendx-sync' ),
						$email
					);
			}
		}

		set_transient( self::TEST_TRANSIENT, $status, MINUTE_IN_SECONDS );

		$back = ( class_exists( 'EmailSendX_Admin' ) && method_exists( 'EmailSendX_Admin', 'get_admin_url' ) )
			? EmailSendX_Admin::get_admin_url( 'carts' )
			: admin_url( 'admin.php?page=emailsendx-sync&tab=carts' );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * A sample cart for "Send a test cart": the shop's newest products,
	 * linking to the real cart page.
	 *
	 * @return array
	 */
	protected static function sample_event() {
		$items = array();
		$value = 0.0;
		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => 2,
					'return' => 'objects',
				)
			);
			foreach ( (array) $products as $product ) {
				$price    = (float) $product->get_price();
				$value   += $price;
				$image_id = (int) $product->get_image_id();
				$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
				$items[]  = array(
					'name'      => self::clip( wp_strip_all_tags( $product->get_name() ), 200 ),
					'qty'       => 1,
					'price'     => self::format_money( $price ),
					'url'       => (string) $product->get_permalink(),
					'image_url' => $image ? (string) $image : '',
				);
			}
		}
		if ( empty( $items ) ) {
			$items[] = array(
				'name' => __( 'Sample product', 'emailsendx-sync' ),
				'qty'  => 1,
			);
		}
		return self::build_cart_event(
			array(
				'items'    => $items,
				'total'    => self::format_money( $value ),
				'value'    => $value,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
				'count'    => count( $items ),
			),
			function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' )
		);
	}

	/* ─── Privacy: export, erase, policy text ──────────────────────── */

	/**
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['emailsendx-carts'] = array(
			'exporter_friendly_name' => __( 'EmailSendX saved carts', 'emailsendx-sync' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['emailsendx-carts'] = array(
			'eraser_friendly_name' => __( 'EmailSendX saved carts', 'emailsendx-sync' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Whether the carts table exists (it is only created once the feature
	 * has been switched on).
	 *
	 * @return bool
	 */
	protected static function table_ready() {
		$state = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		return is_array( $state ) && ! empty( $state['cart_db'] );
	}

	/**
	 * WordPress personal-data export (Tools → Export Personal Data).
	 *
	 * @param string $email Address being exported.
	 * @return array
	 */
	public static function export_personal_data( $email ) {
		$out = array(
			'data' => array(),
			'done' => true,
		);
		if ( ! self::table_ready() ) {
			return $out;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", strtolower( (string) $email ) ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$snapshot = json_decode( (string) $row['cart'], true );
			$names    = array();
			if ( is_array( $snapshot ) && ! empty( $snapshot['items'] ) ) {
				foreach ( $snapshot['items'] as $line ) {
					if ( ! empty( $line['name'] ) ) {
						$names[] = ( isset( $line['qty'] ) ? (int) $line['qty'] : 1 ) . ' × ' . $line['name'];
					}
				}
			}
			$out['data'][] = array(
				'group_id'    => 'emailsendx-carts',
				'group_label' => __( 'Saved carts (EmailSendX)', 'emailsendx-sync' ),
				'item_id'     => 'emailsendx-cart-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Email', 'emailsendx-sync' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Name', 'emailsendx-sync' ),
						'value' => trim( $row['first_name'] . ' ' . $row['last_name'] ),
					),
					array(
						'name'  => __( 'Cart', 'emailsendx-sync' ),
						'value' => implode( ', ', $names ),
					),
					array(
						'name'  => __( 'Total', 'emailsendx-sync' ),
						'value' => is_array( $snapshot ) && isset( $snapshot['total'] ) ? $snapshot['total'] : '',
					),
					array(
						'name'  => __( 'Status', 'emailsendx-sync' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Last updated (UTC)', 'emailsendx-sync' ),
						'value' => $row['updated_at'],
					),
				),
			);
		}
		return $out;
	}

	/**
	 * WordPress personal-data erasure (Tools → Erase Personal Data).
	 *
	 * @param string $email Address being erased.
	 * @return array
	 */
	public static function erase_personal_data( $email ) {
		$removed = 0;
		if ( self::table_ready() ) {
			global $wpdb;
			$removed = (int) $wpdb->delete( self::table_name(), array( 'email' => strtolower( (string) $email ) ), array( '%s' ) );
		}
		$user = get_user_by( 'email', (string) $email );
		if ( $user ) {
			delete_user_meta( $user->ID, self::USER_META_CONSENT );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested wording for the site's privacy policy (Settings → Privacy).
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! self::is_enabled() ) {
			return;
		}
		$content = '<p>' . esc_html__( 'If you enter your email address at checkout and leave without completing your order, we save your email address, name and the contents of your cart so we can email you a reminder with a link back to it. You can decline at checkout, and every reminder has an unsubscribe link.', 'emailsendx-sync' ) . '</p>'
			. '<p>' . esc_html__( 'Saved carts are deleted after 30 days. The reminders are sent through EmailSendX, which receives your email address, name and the cart contents for that purpose.', 'emailsendx-sync' ) . '</p>';
		wp_add_privacy_policy_content( 'EmailSendX for WordPress', wp_kses_post( $content ) );
	}
}
