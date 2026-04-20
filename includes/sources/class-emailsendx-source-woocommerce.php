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
	 * Cache of the unique-email customer set built by build_customer_map().
	 * Keyed by a hash of the call args so repeated page iterations inside
	 * a single request don't re-scan orders. ShaonPro.
	 *
	 * @var array<string,array>|null
	 */
	protected static $customer_map_cache = null;

	/**
	 * 60-second transient key for the count_total() value.
	 */
	const COUNT_TRANS = 'emailsendx_wc_customer_count';

	/**
	 * Total customer count — unique emails from paid orders (HPOS-aware)
	 * plus users with role=customer. Cached in a 60s transient to avoid
	 * repeated heavy queries during polling.
	 *
	 * @param array $args Unused, kept for signature parity.
	 * @return int
	 */
	public function count_total( $args = array() ) {
		unset( $args );
		if ( ! $this->wc_active ) {
			return 0;
		}

		$cached = get_transient( self::COUNT_TRANS );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$count = 0;

		if ( self::hpos_enabled() ) {
			// HPOS: dedicated orders table. ShaonPro.
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT LOWER(billing_email))
				 FROM {$table}
				 WHERE billing_email <> ''
				   AND status IN ('wc-completed','wc-processing','wc-on-hold')"
			);
		} else {
			// Legacy: postmeta `_billing_email` on shop_order CPT.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT LOWER(pm.meta_value))
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_billing_email'
				   AND pm.meta_value <> ''
				   AND p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing','wc-on-hold')"
			);
		}

		// Also count any customer-role users with no order (edge case).
		$user_counts = count_users();
		if ( is_array( $user_counts ) && ! empty( $user_counts['avail_roles']['customer'] ) ) {
			// Can't cheaply dedupe without loading emails, so take max —
			// the build_customer_map() pass will give the true unique set.
			$count = max( $count, (int) $user_counts['avail_roles']['customer'] );
		}

		set_transient( self::COUNT_TRANS, $count, 60 );

		return $count;
	}

	/**
	 * One page of customers, enriched with WC data. Pulls from the full
	 * unique-email customer map (paid-order emails + customer-role users)
	 * and slices a page at a time. Guests (no user) surface with
	 * `user_id=0`. ShaonPro.
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

		$map = $this->build_customer_map();
		if ( empty( $map ) ) {
			return array();
		}

		$offset = ( $page - 1 ) * $per_page;
		if ( $offset >= count( $map ) ) {
			return array();
		}

		$slice = array_slice( $map, $offset, $per_page );

		$out = array();
		foreach ( $slice as $entry ) {
			$out[] = array(
				'user_id'         => (int) ( $entry['user_id'] ?? 0 ),
				'user_email'      => (string) $entry['email'],
				'first_name'      => (string) ( $entry['first_name'] ?? '' ),
				'last_name'       => (string) ( $entry['last_name'] ?? '' ),
				'billing_company' => (string) ( $entry['billing_company'] ?? '' ),
				'billing_phone'   => (string) ( $entry['billing_phone'] ?? '' ),
				'billing_country' => (string) ( $entry['billing_country'] ?? '' ),
				'billing_city'    => (string) ( $entry['billing_city'] ?? '' ),
				'orders_count'    => (int) ( $entry['orders_count'] ?? 0 ),
				'total_spent'     => (float) ( $entry['total_spent'] ?? 0 ),
				'last_order_date' => isset( $entry['last_order_date'] ) ? (string) $entry['last_order_date'] : null,
				'meta'            => isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : array(),
			);
		}

		return $out;
	}

	/**
	 * Build the unique-email customer map. Walks all paid orders via
	 * `wc_get_orders()` in chunks (HPOS-safe because we use the high-level
	 * API which handles HPOS internally), then merges in customer-role
	 * users that have no orders. Dedupes by lowercased email. ShaonPro.
	 *
	 * @return array<int,array> Indexed list of customer entries.
	 */
	protected function build_customer_map() {
		if ( null !== self::$customer_map_cache ) {
			return self::$customer_map_cache;
		}

		$map = array(); // email (lowercased) => entry.

		if ( ! function_exists( 'wc_get_orders' ) ) {
			self::$customer_map_cache = array();
			return array();
		}

		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array();
		if ( empty( $paid_statuses ) ) {
			$paid_statuses = array( 'completed', 'processing' );
		}

		$chunk    = 200;
		$offset   = 0;
		$guard    = 200; // hard cap: 40k orders to prevent runaways.
		$date_cut = gmdate( 'Y-m-d H:i:s', time() - 2 * YEAR_IN_SECONDS );

		while ( $guard-- > 0 ) {
			$order_ids = wc_get_orders(
				array(
					'limit'        => $chunk,
					'offset'       => $offset,
					'return'       => 'ids',
					'status'       => $paid_statuses,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'date_created' => '>' . $date_cut,
				)
			);

			if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
				break;
			}

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}

				$email = (string) $order->get_billing_email();
				if ( '' === $email ) {
					continue;
				}
				$key = strtolower( trim( $email ) );
				if ( '' === $key || ! is_email( $key ) ) {
					continue;
				}

				$user_id = (int) $order->get_user_id();
				$total   = (float) $order->get_total();

				$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
				$created_str = null;
				if ( $created && method_exists( $created, 'date' ) ) {
					$created_str = (string) $created->date( 'Y-m-d H:i:s' );
				}

				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = array(
						'email'           => $key,
						'user_id'         => $user_id,
						'first_name'      => (string) $order->get_billing_first_name(),
						'last_name'       => (string) $order->get_billing_last_name(),
						'billing_company' => (string) $order->get_billing_company(),
						'billing_phone'   => (string) $order->get_billing_phone(),
						'billing_country' => (string) $order->get_billing_country(),
						'billing_city'    => (string) $order->get_billing_city(),
						'orders_count'    => 0,
						'total_spent'     => 0.0,
						'last_order_date' => null,
						'meta'            => array(),
					);
				}

				$entry = &$map[ $key ];

				// Prefer the registered user_id if any order carries one.
				if ( 0 === (int) $entry['user_id'] && $user_id > 0 ) {
					$entry['user_id'] = $user_id;
				}
				$entry['orders_count'] = (int) $entry['orders_count'] + 1;
				$entry['total_spent']  = (float) $entry['total_spent'] + $total;

				if ( null !== $created_str ) {
					if ( null === $entry['last_order_date'] || strcmp( $created_str, (string) $entry['last_order_date'] ) > 0 ) {
						$entry['last_order_date'] = $created_str;
					}
				}

				// Lazy: only reach for billing meta if nothing filled the first pass.
				foreach ( array( 'billing_company', 'billing_phone', 'billing_country', 'billing_city', 'first_name', 'last_name' ) as $k ) {
					if ( '' === (string) $entry[ $k ] ) {
						$getter = 'get_billing_' . ( 'first_name' === $k || 'last_name' === $k ? $k : substr( $k, 8 ) );
						if ( method_exists( $order, $getter ) ) {
							$entry[ $k ] = (string) $order->{$getter}();
						}
					}
				}

				unset( $entry );
			}

			if ( count( $order_ids ) < $chunk ) {
				break;
			}
			$offset += $chunk;
		}

		// Merge in customer-role users with no orders.
		$user_query = new WP_User_Query(
			array(
				'role'   => 'customer',
				'number' => -1,
				'fields' => array( 'ID', 'user_email' ),
			)
		);
		$users = (array) $user_query->get_results();

		if ( ! empty( $users ) ) {
			$user_ids = array();
			foreach ( $users as $u ) {
				if ( is_object( $u ) && isset( $u->ID ) ) {
					$user_ids[] = (int) $u->ID;
				}
			}
			if ( ! empty( $user_ids ) ) {
				update_meta_cache( 'user', $user_ids );
			}

			foreach ( $users as $u ) {
				if ( ! is_object( $u ) || empty( $u->user_email ) ) {
					continue;
				}
				$key = strtolower( trim( (string) $u->user_email ) );
				if ( '' === $key || ! is_email( $key ) ) {
					continue;
				}
				$uid = (int) $u->ID;
				$meta = get_user_meta( $uid );

				if ( isset( $map[ $key ] ) ) {
					// Attach user_id + top up empties from user meta.
					if ( 0 === (int) $map[ $key ]['user_id'] ) {
						$map[ $key ]['user_id'] = $uid;
					}
					foreach ( array( 'first_name', 'last_name', 'billing_company', 'billing_phone', 'billing_country', 'billing_city' ) as $k ) {
						if ( '' === (string) $map[ $key ][ $k ] ) {
							$map[ $key ][ $k ] = self::pick_meta( $meta, $k );
						}
					}
					if ( empty( $map[ $key ]['meta'] ) ) {
						$map[ $key ]['meta'] = self::extract_billing_meta( $meta );
					}
				} else {
					$map[ $key ] = array(
						'email'           => $key,
						'user_id'         => $uid,
						'first_name'      => self::pick_meta( $meta, 'first_name' ),
						'last_name'       => self::pick_meta( $meta, 'last_name' ),
						'billing_company' => self::pick_meta( $meta, 'billing_company' ),
						'billing_phone'   => self::pick_meta( $meta, 'billing_phone' ),
						'billing_country' => self::pick_meta( $meta, 'billing_country' ),
						'billing_city'    => self::pick_meta( $meta, 'billing_city' ),
						'orders_count'    => 0,
						'total_spent'     => 0.0,
						'last_order_date' => null,
						'meta'            => self::extract_billing_meta( $meta ),
					);
				}
			}
		}

		$indexed = array_values( $map );
		self::$customer_map_cache = $indexed;
		return $indexed;
	}

	/**
	 * Is WooCommerce HPOS (custom orders table) enabled?
	 *
	 * @return bool
	 */
	protected static function hpos_enabled() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& method_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			try {
				return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			} catch ( Exception $e ) {
				return false;
			}
		}
		return false;
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
