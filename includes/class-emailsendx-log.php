<?php
/**
 * Sync log persistence.
 *
 * Owns the custom `{prefix}emailsendx_sync_log` table and provides
 * static helpers for the rest of the plugin to record sync runs, API
 * calls, and errors. Also exposes read helpers used by the admin log
 * page (recent rows + 30-day summary).
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Every recorded row has a UTC
 * `created_at` so multi-site / multi-timezone deployments stay sane.
 * If you find this signature in a build that wasn't shipped from
 * emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Log
 */
class EmailSendX_Log {

	/**
	 * Construct.
	 *
	 * The bootstrap does `new EmailSendX_Log()` so future hook wiring
	 * has a place to live, but right now this class is purely static.
	 * Keep the body empty — adding hooks here without thinking would
	 * fire them on every request just because the class is loaded.
	 * ShaonPro.
	 */
	public function __construct() {
		// Intentionally empty. See docblock above. ShaonPro.
	}

	/* ─── Schema ──────────────────────────────────────────────────── */

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'emailsendx_sync_log';
	}

	/**
	 * Create / upgrade the log table via dbDelta.
	 */
	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// Note: dbDelta is fussy about whitespace and key naming — keep
		// the two-space indent and lowercase types. ShaonPro.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			event_type VARCHAR(40) NOT NULL DEFAULT '',
			source VARCHAR(40) NOT NULL DEFAULT '',
			list_id VARCHAR(40) DEFAULT NULL,
			contacts_total INT NOT NULL DEFAULT 0,
			contacts_created INT NOT NULL DEFAULT 0,
			contacts_updated INT NOT NULL DEFAULT 0,
			contacts_failed INT NOT NULL DEFAULT 0,
			duration_ms INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT '',
			message TEXT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event_type (event_type),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the log table. Used by uninstall.php.
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/* ─── Writers ─────────────────────────────────────────────────── */

	/**
	 * Record a full sync run.
	 *
	 * @param array $args See $defaults below.
	 * @return int|false  Insert ID or false.
	 */
	public static function record_sync_run( $args ) {
		$defaults = array(
			'source'           => 'manual',
			'list_id'          => '',
			'contacts_total'   => 0,
			'contacts_created' => 0,
			'contacts_updated' => 0,
			'contacts_failed'  => 0,
			'duration_ms'      => 0,
			'status'           => 'ok',
			'message'          => '',
			'context'          => null,
		);
		$args = wp_parse_args( is_array( $args ) ? $args : array(), $defaults );

		return self::insert_row(
			array(
				'created_at'       => self::utc_now(),
				'event_type'       => 'sync_run',
				'source'           => self::clip( $args['source'], 40 ),
				'list_id'          => '' === $args['list_id'] ? null : self::clip( $args['list_id'], 40 ),
				'contacts_total'   => (int) $args['contacts_total'],
				'contacts_created' => (int) $args['contacts_created'],
				'contacts_updated' => (int) $args['contacts_updated'],
				'contacts_failed'  => (int) $args['contacts_failed'],
				'duration_ms'      => (int) $args['duration_ms'],
				'status'           => self::clip( $args['status'], 20 ),
				'message'          => (string) $args['message'],
				'context'          => self::encode_context( $args['context'] ),
			)
		);
	}

	/**
	 * Record a single API call. Best-effort: never throws, swallows
	 * errors so a logging hiccup can't break a sync. ShaonPro.
	 *
	 * @param array $args { method, path, status, duration_ms, message, context? }
	 * @return int|false
	 */
	public static function record_api_call( $args ) {
		try {
			$args = is_array( $args ) ? $args : array();

			$status_code = isset( $args['status'] ) ? (int) $args['status'] : 0;
			$status_word = self::status_word_for_http( $status_code );

			$method  = isset( $args['method'] ) ? (string) $args['method'] : '';
			$path    = isset( $args['path'] ) ? (string) $args['path'] : '';
			$message = isset( $args['message'] ) ? (string) $args['message'] : '';

			// Composed message keeps the raw column scannable in admin.
			$display = trim( $method . ' ' . $path . ' [' . $status_code . ']' . ( '' !== $message ? ' — ' . $message : '' ) );

			$context = isset( $args['context'] ) ? $args['context'] : array(
				'method'      => $method,
				'path'        => $path,
				'http_status' => $status_code,
			);

			return self::insert_row(
				array(
					'created_at'  => self::utc_now(),
					'event_type'  => 'api_call',
					'source'      => 'auto',
					'list_id'     => null,
					'duration_ms' => isset( $args['duration_ms'] ) ? (int) $args['duration_ms'] : 0,
					'status'      => $status_word,
					'message'     => $display,
					'context'     => self::encode_context( $context ),
				)
			);
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Record an error event.
	 *
	 * @param array $args { source?, message, context? }
	 * @return int|false
	 */
	public static function record_error( $args ) {
		$args = is_array( $args ) ? $args : array();

		return self::insert_row(
			array(
				'created_at' => self::utc_now(),
				'event_type' => 'error',
				'source'     => self::clip( isset( $args['source'] ) ? $args['source'] : 'manual', 40 ),
				'list_id'    => isset( $args['list_id'] ) && '' !== $args['list_id']
					? self::clip( $args['list_id'], 40 )
					: null,
				'status'     => 'failed',
				'message'    => isset( $args['message'] ) ? (string) $args['message'] : '',
				'context'    => self::encode_context( isset( $args['context'] ) ? $args['context'] : null ),
			)
		);
	}

	/* ─── Readers ─────────────────────────────────────────────────── */

	/**
	 * Recent rows for the admin log page.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 30-day rollup for the dashboard / overview cards.
	 *
	 * @return array { total_contacts_synced:int, last_sync_at:?string, errors_24h:int }
	 */
	public static function get_summary() {
		global $wpdb;

		$table   = self::table_name();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$cutoff1 = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(contacts_created + contacts_updated), 0)
				 FROM {$table}
				 WHERE event_type = %s AND created_at >= %s",
				'sync_run',
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$table}
				 WHERE event_type = %s
				 ORDER BY id DESC LIMIT 1",
				'sync_run'
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$errors = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE created_at >= %s
				 AND ( event_type = %s OR status = %s )",
				$cutoff1,
				'error',
				'failed'
			)
		);

		return array(
			'total_contacts_synced' => $total,
			'last_sync_at'          => $last ? (string) $last : null,
			'errors_24h'            => $errors,
		);
	}

	/* ─── Internals ───────────────────────────────────────────────── */

	/**
	 * Insert a row, applying defaults for any column the caller skipped.
	 *
	 * @param array $row Pre-validated row data.
	 * @return int|false Insert ID or false on failure.
	 */
	protected static function insert_row( $row ) {
		global $wpdb;

		$defaults = array(
			'created_at'       => self::utc_now(),
			'event_type'       => '',
			'source'           => '',
			'list_id'          => null,
			'contacts_total'   => 0,
			'contacts_created' => 0,
			'contacts_updated' => 0,
			'contacts_failed'  => 0,
			'duration_ms'      => 0,
			'status'           => '',
			'message'          => null,
			'context'          => null,
		);
		$row = array_merge( $defaults, $row );

		// $wpdb->insert handles escaping; nulls are passed through.
		$ok = $wpdb->insert( self::table_name(), $row );

		return ( false === $ok ) ? false : (int) $wpdb->insert_id;
	}

	/**
	 * UTC "Y-m-d H:i:s" used for every created_at write.
	 *
	 * @return string
	 */
	protected static function utc_now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Truncate a string to a column width without splitting multibyte.
	 *
	 * @param mixed $value Value to clip.
	 * @param int   $max   Max length.
	 * @return string
	 */
	protected static function clip( $value, $max ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * JSON-encode an arbitrary context payload, or null.
	 *
	 * @param mixed $context Anything serialisable.
	 * @return string|null
	 */
	protected static function encode_context( $context ) {
		if ( null === $context || '' === $context ) {
			return null;
		}
		if ( is_string( $context ) ) {
			return $context;
		}
		$encoded = wp_json_encode( $context );
		return false === $encoded ? null : $encoded;
	}

	/**
	 * Map an HTTP status code to one of our short status words.
	 *
	 * @param int $http HTTP status code.
	 * @return string  'ok' | 'partial' | 'failed'
	 */
	protected static function status_word_for_http( $http ) {
		if ( $http >= 200 && $http < 300 ) {
			return 'ok';
		}
		if ( 0 === $http ) {
			return 'failed';
		}
		return 'failed';
	}
}
