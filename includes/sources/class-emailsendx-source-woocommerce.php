<?php
/**
 * EmailSendX sync source — WooCommerce customers.
 *
 * Same iterator contract as `EmailSendX_Source_Users` but enriches each
 * row with WC-specific fields (billing address, order count, total
 * spent, last order date). Designed to be safe to load even when
 * WooCommerce isn't active — every WC call is `function_exists`-guarded
 * and the constructor records an `wc_active` flag that short-circuits
 * the iterator.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The WC enrichment shape is the
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
 * Class EmailSendX_Source_WooCommerce
 */
class EmailSendX_Source_WooCommerce {

	/**
	 * Whether WC is loaded — gates every method.
	 *
	 * @var bool
	 */
	protected $wc_active = false;

	/**
	 * Capture WC availability once, at construct time. ShaonPro.
	 */
	public function __construct() {
		$this->wc_active = class_exists( 'WooCommerce' );
	}

	/**
	 * Total customer count (users with the `customer` role).
	 *
	 * @param array $args Unused, kept for signature parity.
	 * @return int
	 */
	public function count_total( $args = array() ) {
		unset( $args );
		if ( ! $this->wc_active ) {
			return 0;
		}

		$counts = count_users();
		if ( ! is_array( $counts ) || empty( $counts['avail_roles']['customer'] ) ) {
			return 0;
		}
		return (int) $counts['avail_roles']['customer'];
	}

	/**
	 * One page of customers, enriched with WC data.
	 *
	 * @param int   $page     1-indexed.
	 * @param int   $per_page Page size.
	 * @param array $args     Unused.
	 * @return array<int,array>
	 */
	public function iterate( $page, $per_page = 500, $args = array() ) {
		unset( $args );
		if ( ! $this->wc_active ) {
			return array();
		}

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 500, (int) $per_page ) );

		$query = new WP_User_Query(
			array(
				'role'    => 'customer',
				'number'  => $per_page,
				'paged'   => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'all',
			)
		);

		$users = (array) $query->get_results();
		if ( empty( $users ) ) {
			return array();
		}

		// Prime meta cache for the whole page in one query. ShaonPro.
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

			$id       = (int) $u->ID;
			$all_meta = get_user_meta( $id );

			$row = array(
				'user_id'         => $id,
				'user_email'      => (string) $u->user_email,
				'first_name'      => self::pick_meta( $all_meta, 'first_name' ),
				'last_name'       => self::pick_meta( $all_meta, 'last_name' ),
				'billing_company' => self::pick_meta( $all_meta, 'billing_company' ),
				'billing_phone'   => self::pick_meta( $all_meta, 'billing_phone' ),
				'billing_country' => self::pick_meta( $all_meta, 'billing_country' ),
				'billing_city'    => self::pick_meta( $all_meta, 'billing_city' ),
				'orders_count'    => self::safe_orders_count( $id ),
				'total_spent'     => self::safe_total_spent( $id ),
				'last_order_date' => self::safe_last_order_date( $id ),
				'meta'            => self::extract_billing_meta( $all_meta ),
			);

			$out[] = $row;
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
			'first_name'      => __( 'First name', 'emailsendx-sync' ),
			'last_name'       => __( 'Last name', 'emailsendx-sync' ),
			'billing_company' => __( 'Billing company', 'emailsendx-sync' ),
			'billing_phone'   => __( 'Billing phone', 'emailsendx-sync' ),
			'billing_country' => __( 'Billing country', 'emailsendx-sync' ),
			'billing_city'    => __( 'Billing city', 'emailsendx-sync' ),
			'orders_count'    => __( 'Order count', 'emailsendx-sync' ),
			'total_spent'     => __( 'Total spent', 'emailsendx-sync' ),
			'last_order_date' => __( 'Last order date', 'emailsendx-sync' ),
			'__meta'          => __( '(Other billing/shipping meta…)', 'emailsendx-sync' ),
		);
	}

	/* ─── Internals ────────────────────────────────────────────────── */

	/**
	 * Pull a single scalar from the raw user_meta map.
	 *
	 * @param array  $raw Raw meta.
	 * @param string $key Meta key.
	 * @return string
	 */
	protected static function pick_meta( $raw, $key ) {
		if ( ! is_array( $raw ) || ! isset( $raw[ $key ][0] ) ) {
			return '';
		}
		$value = $raw[ $key ][0];
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Order count — uses WC helper when available, falls back to 0.
	 *
	 * @param int $user_id WP user id.
	 * @return int
	 */
	protected static function safe_orders_count( $user_id ) {
		if ( function_exists( 'wc_get_customer_order_count' ) ) {
			try {
				return (int) wc_get_customer_order_count( $user_id );
			} catch ( Exception $e ) {
				return 0;
			}
		}

		// Older WC fallback. ShaonPro.
		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$c = new WC_Customer( $user_id );
				return (int) $c->get_order_count();
			} catch ( Exception $e ) {
				return 0;
			}
		}
		return 0;
	}

	/**
	 * Total spent — uses WC helper when available.
	 *
	 * @param int $user_id WP user id.
	 * @return float
	 */
	protected static function safe_total_spent( $user_id ) {
		if ( function_exists( 'wc_get_customer_total_spent' ) ) {
			try {
				return (float) wc_get_customer_total_spent( $user_id );
			} catch ( Exception $e ) {
				return 0.0;
			}
		}

		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$c = new WC_Customer( $user_id );
				return (float) $c->get_total_spent();
			} catch ( Exception $e ) {
				return 0.0;
			}
		}
		return 0.0;
	}

	/**
	 * Last order date in MySQL format, or null. ShaonPro.
	 *
	 * @param int $user_id WP user id.
	 * @return string|null
	 */
	protected static function safe_last_order_date( $user_id ) {
		if ( ! function_exists( 'wc_get_customer_last_order' ) ) {
			return null;
		}

		try {
			$order = wc_get_customer_last_order( $user_id );
			if ( ! $order ) {
				return null;
			}

			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			if ( ! $created ) {
				return null;
			}

			// WC_DateTime → date('Y-m-d H:i:s').
			if ( method_exists( $created, 'date' ) ) {
				return (string) $created->date( 'Y-m-d H:i:s' );
			}
			return (string) $created;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Extract billing/shipping meta into a flat scalar bag for the
	 * mapper. Skips serialised values and the heavyweight WC internals.
	 *
	 * @param array $raw Raw user_meta.
	 * @return array<string,scalar>
	 */
	protected static function extract_billing_meta( $raw ) {
		$flat = array();
		if ( ! is_array( $raw ) ) {
			return $flat;
		}

		foreach ( $raw as $key => $values ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$is_billing  = 0 === strpos( $key, 'billing_' );
			$is_shipping = 0 === strpos( $key, 'shipping_' );
			if ( ! $is_billing && ! $is_shipping ) {
				continue;
			}

			$value = is_array( $values ) ? reset( $values ) : $values;
			if ( is_serialized( $value ) || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$flat[ $key ] = is_scalar( $value ) ? $value : (string) $value;
		}

		return $flat;
	}
}
