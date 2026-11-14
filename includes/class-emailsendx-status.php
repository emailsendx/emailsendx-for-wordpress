<?php
/**
 * Email health from EmailSendX — pulls each contact's verdict (sendable,
 * invalid, disposable, bounced, unsubscribed, suppressed…) back into
 * WordPress so the site owner can see and clean up bad addresses that
 * EmailSendX already validated or suppressed.
 *
 * Pull, not push: a WP-cron job (every six hours) and a "Check now"
 * button call GET /api/v1/contacts/status with the site's API key. The
 * site needs no inbound endpoint.
 *
 * Only addresses that belong to this site are stored — WP users and
 * WooCommerce order emails. Other contacts in the workspace are ignored,
 * so a shared workspace never copies foreign addresses into this database.
 *
 * Nothing is deleted or changed on the WP side. The plugin only marks
 * users (Users screen column + filter) and offers a CSV; the site owner
 * decides what to do.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Status
 */
class EmailSendX_Status {

	/** Bump when the table definition changes; dbDelta runs on mismatch. */
	const DB_VERSION = '1';

	/** Recurring pull. */
	const CRON_HOOK = 'emailsendx_sync_status_pull';

	/** One-off continuation when a run hits its time budget. */
	const CONTINUE_HOOK = 'emailsendx_sync_status_continue';

	/** Custom interval name (six hours). */
	const CRON_SCHEDULE = 'emailsendx_sync_six_hours';

	/** State key inside EMAILSENDX_SYNC_OPT_STATE. */
	const STATE_KEY = 'email_health';

	/** Transient holding the flash message after "Check now". */
	const FLASH_TRANSIENT = 'emailsendx_sync_status_flash';

	/** Nonce actions. */
	const NONCE_PULL   = 'emailsendx_status_pull';
	const NONCE_EXPORT = 'emailsendx_status_export';

	/** Query var on users.php. */
	const FILTER_VAR = 'esx_health';

	/** Rows asked for per API page. */
	const PAGE_SIZE = 500;

	/** Seconds one run may spend before it hands over to a continuation. */
	const TIME_BUDGET = 20;

	/** Run a full refresh (catches removed suppressions) at least this often. */
	const FULL_EVERY = WEEK_IN_SECONDS;

	/** Every verdict the API can return. */
	const VERDICTS = array( 'sendable', 'risky', 'disposable', 'invalid', 'bounced', 'unsubscribed', 'pending', 'suppressed' );

	/** Verdicts EmailSendX will not send to — the "needs cleanup" group. */
	const PROBLEM_VERDICTS = array( 'disposable', 'invalid', 'bounced', 'unsubscribed', 'suppressed' );

	/**
	 * Per-request cache for the Users column.
	 *
	 * @var array<string,array|null>
	 */
	protected static $row_cache = array();

	/**
	 * Hook wiring.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'run_cron' ) );

		add_action( 'admin_post_emailsendx_status_pull', array( __CLASS__, 'handle_pull' ) );
		add_action( 'admin_post_emailsendx_status_export', array( __CLASS__, 'handle_export' ) );

		// Users screen: column, filter, styles.
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_user_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_user_column' ), 10, 3 );
		add_action( 'restrict_manage_users', array( __CLASS__, 'render_user_filter' ) );
		add_action( 'pre_user_query', array( __CLASS__, 'filter_user_query' ) );
		add_action( 'admin_head-users.php', array( __CLASS__, 'print_users_css' ) );

		// A user deleted in WP is gone from this table too.
		add_action( 'deleted_user', array( __CLASS__, 'forget_user' ) );

		if ( emailsendx_sync_is_configured() ) {
			self::maybe_install();
		}
	}

	/* ─── Table ────────────────────────────────────────────────────── */

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'emailsendx_email_health';
	}

	/**
	 * Create / upgrade the table when the stored version is behind.
	 */
	public static function maybe_install() {
		$state = self::get_state();
		if ( isset( $state['db'] ) && self::DB_VERSION === (string) $state['db'] ) {
			return;
		}
		self::install_table();
		$state['db'] = self::DB_VERSION;
		self::save_state( $state );
	}

	/**
	 * dbDelta the table. Times are stored in UTC.
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is fussy about whitespace and key naming — keep the
		// two spaces after PRIMARY KEY. ShaonPro.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			verdict VARCHAR(20) NOT NULL DEFAULT '',
			contact_status VARCHAR(20) NOT NULL DEFAULT '',
			validation VARCHAR(40) NOT NULL DEFAULT '',
			validation_score SMALLINT NULL,
			validated_at DATETIME NULL,
			suppression_reason VARCHAR(40) NOT NULL DEFAULT '',
			remote_updated_at DATETIME NULL,
			pulled_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY user_id (user_id),
			KEY verdict (verdict)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Whether the table exists at the current version.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		$state = self::get_state();
		return isset( $state['db'] ) && self::DB_VERSION === (string) $state['db'];
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

	/* ─── State ────────────────────────────────────────────────────── */

	/**
	 * @return array{db?:string,since?:string,cursor?:string,cursor_full?:bool,run_started?:string,last_full?:int,last_run?:int,last_error?:string,full_next?:bool}
	 */
	public static function get_state() {
		$all = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		$all = is_array( $all ) ? $all : array();
		return isset( $all[ self::STATE_KEY ] ) && is_array( $all[ self::STATE_KEY ] ) ? $all[ self::STATE_KEY ] : array();
	}

	/**
	 * @param array $state New state for this feature.
	 */
	protected static function save_state( $state ) {
		$all = get_option( EMAILSENDX_SYNC_OPT_STATE, array() );
		$all = is_array( $all ) ? $all : array();
		$all[ self::STATE_KEY ] = $state;
		update_option( EMAILSENDX_SYNC_OPT_STATE, $all );
	}

	/* ─── Cron ─────────────────────────────────────────────────────── */

	/**
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every six hours (EmailSendX)', 'emailsendx-sync' ),
			);
		}
		return $schedules;
	}

	/**
	 * Keep the pull scheduled exactly while the plugin is connected.
	 */
	public static function ensure_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( emailsendx_sync_is_configured() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Remove scheduled events. Called on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Cron entry point.
	 */
	public static function run_cron() {
		self::run();
	}

	/* ─── Pull ─────────────────────────────────────────────────────── */

	/**
	 * Pull statuses from EmailSendX. Resumes an unfinished run; otherwise
	 * starts a delta from the last `sync_until`, or a full refresh when
	 * there is none, a week has passed, the API asked for one, or
	 * `$force_full` is set.
	 *
	 * @param bool $force_full Start a full refresh now.
	 * @return array{ok:bool,message:string,stored:int,done:bool}
	 */
	public static function run( $force_full = false ) {
		$out = array(
			'ok'      => false,
			'message' => '',
			'stored'  => 0,
			'done'    => false,
		);

		if ( ! emailsendx_sync_is_configured() || ! class_exists( 'EmailSendX_API' ) ) {
			$out['message'] = __( 'Connect to EmailSendX first.', 'emailsendx-sync' );
			return $out;
		}

		// One run at a time (cron + button can overlap).
		$lock = 'emailsendx_status_lock';
		if ( get_transient( $lock ) ) {
			$out['ok']      = true;
			$out['message'] = __( 'A check is already running. Refresh this page in a minute.', 'emailsendx-sync' );
			return $out;
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		self::maybe_install();
		$state = self::get_state();
		$now   = time();

		$resuming = ! empty( $state['cursor'] ) && ! $force_full;
		if ( $resuming ) {
			$full = ! empty( $state['cursor_full'] );
		} else {
			$full = $force_full
				|| empty( $state['since'] )
				|| ! empty( $state['full_next'] )
				|| empty( $state['last_full'] )
				|| ( $now - (int) $state['last_full'] ) > self::FULL_EVERY;
			$state['cursor']      = '';
			$state['cursor_full'] = $full;
			$state['run_started'] = gmdate( 'Y-m-d H:i:s', $now );
		}

		$api     = EmailSendX_API::instance();
		$cursor  = isset( $state['cursor'] ) ? (string) $state['cursor'] : '';
		$since   = $full ? '' : ( isset( $state['since'] ) ? (string) $state['since'] : '' );
		$started = microtime( true );
		$stored  = 0;
		$done    = false;
		$error   = '';

		while ( true ) {
			$res = $api->get_contact_statuses( $since, $cursor, self::PAGE_SIZE );
			if ( is_wp_error( $res ) ) {
				$error = self::explain_error( $res );
				$data  = $res->get_error_data();
				if ( '' !== $cursor && is_array( $data ) && isset( $data['status'] ) && 400 === (int) $data['status'] ) {
					// Stale cursor — start the next run from scratch.
					$state['cursor']    = '';
					$state['full_next'] = true;
				}
				break;
			}

			$rows    = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
			$stored += self::store_page( $rows );

			if ( ! empty( $res['full_resync_recommended'] ) ) {
				$state['full_next'] = true;
			}

			$next = isset( $res['next_cursor'] ) && is_string( $res['next_cursor'] ) ? $res['next_cursor'] : '';
			if ( '' === $next ) {
				$done = true;
				if ( ! empty( $res['sync_until'] ) ) {
					$state['since'] = (string) $res['sync_until'];
				}
				break;
			}
			$cursor = $next;
			$state['cursor'] = $cursor;
			self::save_state( $state ); // Survive a fatal mid-run.

			if ( ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
				break;
			}
		}

		if ( $done ) {
			if ( $full ) {
				// Contacts gone from EmailSendX: drop rows this full run did not touch.
				self::prune_older_than( isset( $state['run_started'] ) ? $state['run_started'] : gmdate( 'Y-m-d H:i:s', $now ) );
				$state['last_full'] = $now;
				$state['full_next'] = false;
			}
			$state['cursor']      = '';
			$state['cursor_full'] = false;
		} elseif ( '' === $error ) {
			// Budget used up — continue in a minute.
			if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
			}
		}

		$state['last_run']   = $now;
		$state['last_error'] = $error;
		self::save_state( $state );
		delete_transient( $lock );

		$out['stored'] = $stored;
		$out['done']   = $done;
		if ( '' !== $error ) {
			$out['message'] = $error;
		} else {
			$out['ok']      = true;
			$out['message'] = $done
				? __( 'Email health is up to date.', 'emailsendx-sync' )
				: __( 'Still checking — the rest continues in the background. Refresh this page in a few minutes.', 'emailsendx-sync' );
		}

		if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'record_error' ) && '' !== $error ) {
			EmailSendX_Log::record_error(
				array(
					'source'  => 'email_health',
					'message' => $error,
				)
			);
		}

		return $out;
	}

	/**
	 * Plain-language message for a failed pull.
	 *
	 * @param WP_Error $err Error from the API client.
	 * @return string
	 */
	protected static function explain_error( $err ) {
		$data   = $err->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 403 === $status ) {
			return __( 'Your API key cannot read contacts. In EmailSendX, create a key with the "contacts:read" scope (or click Connect again on the Settings tab) and save it here.', 'emailsendx-sync' );
		}
		if ( 404 === $status ) {
			return __( 'Your EmailSendX account does not offer email health yet. It will start working once EmailSendX is updated — nothing to do here.', 'emailsendx-sync' );
		}
		return sprintf(
			/* translators: %s: error message */
			__( 'Could not get email health from EmailSendX: %s', 'emailsendx-sync' ),
			$err->get_error_message()
		);
	}

	/**
	 * Store the rows of one API page that belong to this site.
	 *
	 * @param array $rows API rows.
	 * @return int Rows written.
	 */
	public static function store_page( $rows ) {
		global $wpdb;

		$by_email = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['email'] ) ) {
				continue;
			}
			$email = strtolower( trim( (string) $row['email'] ) );
			if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
				continue;
			}
			$verdict = isset( $row['verdict'] ) ? (string) $row['verdict'] : '';
			if ( ! in_array( $verdict, self::VERDICTS, true ) ) {
				continue;
			}
			$by_email[ $email ] = $row;
		}
		if ( empty( $by_email ) ) {
			return 0;
		}

		$known = self::site_emails( array_keys( $by_email ) );
		if ( empty( $known ) ) {
			return 0;
		}

		$table   = self::table_name();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$written = 0;

		foreach ( $known as $email => $user_id ) {
			$row   = $by_email[ $email ];
			$score = isset( $row['validation_score'] ) && is_numeric( $row['validation_score'] )
				? max( 0, min( 100, (int) $row['validation_score'] ) )
				: null;

			$values = array(
				'email'              => $email,
				'user_id'            => (int) $user_id,
				'verdict'            => (string) $row['verdict'],
				'contact_status'     => self::clip( isset( $row['status'] ) ? $row['status'] : '', 20 ),
				'validation'         => self::clip( isset( $row['validation'] ) ? $row['validation'] : '', 40 ),
				'validation_score'   => $score,
				'validated_at'       => self::to_mysql( isset( $row['validated_at'] ) ? $row['validated_at'] : '' ),
				'suppression_reason' => self::clip( isset( $row['suppression_reason'] ) ? $row['suppression_reason'] : '', 40 ),
				'remote_updated_at'  => self::to_mysql( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ),
				'pulled_at'          => $now,
			);

			// INSERT … ON DUPLICATE KEY UPDATE, with NULLs kept as NULL.
			$cols   = array_keys( $values );
			$holds  = array();
			$params = array();
			foreach ( $values as $v ) {
				if ( null === $v ) {
					$holds[] = 'NULL';
				} elseif ( is_int( $v ) ) {
					$holds[]  = '%d';
					$params[] = $v;
				} else {
					$holds[]  = '%s';
					$params[] = $v;
				}
			}
			$updates = array();
			foreach ( $cols as $c ) {
				if ( 'email' !== $c ) {
					$updates[] = "{$c} = VALUES({$c})";
				}
			}
			$sql = "INSERT INTO {$table} (" . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $holds ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ok = $wpdb->query( $wpdb->prepare( $sql, $params ) );
			if ( false !== $ok ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Which of these emails belong to this site, mapped to their WP user id
	 * (0 for a WooCommerce guest buyer).
	 *
	 * @param string[] $emails Lower-cased emails.
	 * @return array<string,int>
	 */
	public static function site_emails( $emails ) {
		global $wpdb;
		$emails = array_values( array_unique( array_filter( array_map( 'strval', (array) $emails ) ) ) );
		if ( empty( $emails ) ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		$out = array();

		// WP users. MySQL's default collations compare case-insensitively.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$users = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_email FROM {$wpdb->users} WHERE user_email IN ($in)", $emails ), ARRAY_A );
		foreach ( (array) $users as $u ) {
			$out[ strtolower( (string) $u['user_email'] ) ] = (int) $u['ID'];
		}

		// WooCommerce guest buyers.
		$rest = array_values( array_diff( $emails, array_keys( $out ) ) );
		if ( ! empty( $rest ) && class_exists( 'WooCommerce' ) ) {
			$in2 = implode( ',', array_fill( 0, count( $rest ), '%s' ) );
			if ( self::hpos_enabled() ) {
				$table = $wpdb->prefix . 'wc_orders';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT billing_email FROM {$table} WHERE billing_email IN ($in2)", $rest ) );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_billing_email' AND meta_value IN ($in2)", $rest ) );
			}
			foreach ( (array) $found as $e ) {
				$e = strtolower( (string) $e );
				if ( ! isset( $out[ $e ] ) ) {
					$out[ $e ] = 0;
				}
			}
		}

		return $out;
	}

	/**
	 * Delete rows a full refresh did not touch.
	 *
	 * @param string $mysql_utc Run start, UTC.
	 */
	protected static function prune_older_than( $mysql_utc ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE pulled_at < %s", $mysql_utc ) );
	}

	/**
	 * @param int $user_id Deleted user.
	 */
	public static function forget_user( $user_id ) {
		global $wpdb;
		if ( ! self::is_installed() ) {
			return;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d AND user_id > 0", (int) $user_id ) );
	}

	/* ─── Read helpers ─────────────────────────────────────────────── */

	/**
	 * Stored status of one address, or null when unknown.
	 *
	 * @param string $email Address.
	 * @return array|null
	 */
	public static function get_for_email( $email ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! self::is_installed() ) {
			return null;
		}
		if ( array_key_exists( $email, self::$row_cache ) ) {
			return self::$row_cache[ $email ];
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ), ARRAY_A );
		self::$row_cache[ $email ] = $row ? $row : null;
		return self::$row_cache[ $email ];
	}

	/**
	 * Count per verdict, plus split by user / guest for the problem group.
	 *
	 * @return array{by_verdict:array<string,int>,total:int,problem:int,problem_users:int,problem_guests:int}
	 */
	public static function get_counts() {
		global $wpdb;
		$out = array(
			'by_verdict'     => array_fill_keys( self::VERDICTS, 0 ),
			'total'          => 0,
			'problem'        => 0,
			'problem_users'  => 0,
			'problem_guests' => 0,
		);
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT verdict, (user_id > 0) AS is_user, COUNT(*) AS n FROM {$table} GROUP BY verdict, (user_id > 0)", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$v = (string) $r['verdict'];
			$n = (int) $r['n'];
			if ( isset( $out['by_verdict'][ $v ] ) ) {
				$out['by_verdict'][ $v ] += $n;
			}
			$out['total'] += $n;
			if ( in_array( $v, self::PROBLEM_VERDICTS, true ) ) {
				$out['problem'] += $n;
				if ( (int) $r['is_user'] ) {
					$out['problem_users'] += $n;
				} else {
					$out['problem_guests'] += $n;
				}
			}
		}
		return $out;
	}

	/**
	 * Human label for a verdict.
	 *
	 * @param string $verdict Verdict.
	 * @return string
	 */
	public static function label( $verdict ) {
		switch ( $verdict ) {
			case 'sendable':
				return __( 'Sendable', 'emailsendx-sync' );
			case 'risky':
				return __( 'Risky', 'emailsendx-sync' );
			case 'disposable':
				return __( 'Disposable', 'emailsendx-sync' );
			case 'invalid':
				return __( 'Invalid', 'emailsendx-sync' );
			case 'bounced':
				return __( 'Bounced', 'emailsendx-sync' );
			case 'unsubscribed':
				return __( 'Unsubscribed', 'emailsendx-sync' );
			case 'pending':
				return __( 'Awaiting confirmation', 'emailsendx-sync' );
			case 'suppressed':
				return __( 'Suppressed', 'emailsendx-sync' );
		}
		return __( 'Not in EmailSendX', 'emailsendx-sync' );
	}

	/**
	 * Tone for a verdict badge: ok | warn | error | muted.
	 *
	 * @param string $verdict Verdict.
	 * @return string
	 */
	public static function tone( $verdict ) {
		if ( 'sendable' === $verdict ) {
			return 'ok';
		}
		if ( in_array( $verdict, array( 'risky', 'pending', 'unsubscribed' ), true ) ) {
			return 'warn';
		}
		if ( in_array( $verdict, self::PROBLEM_VERDICTS, true ) ) {
			return 'error';
		}
		return 'muted';
	}

	/**
	 * One-line explanation shown as a tooltip.
	 *
	 * @param array $row Stored row.
	 * @return string
	 */
	public static function describe( $row ) {
		$parts = array();
		if ( ! empty( $row['suppression_reason'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: suppression reason, e.g. hard_bounce */
				__( 'Suppressed: %s', 'emailsendx-sync' ),
				str_replace( '_', ' ', (string) $row['suppression_reason'] )
			);
		}
		if ( ! empty( $row['validation'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: validation result, e.g. invalid */
				__( 'Validation: %s', 'emailsendx-sync' ),
				str_replace( '_', ' ', (string) $row['validation'] )
			);
		}
		if ( ! empty( $row['contact_status'] ) && 'active' !== $row['contact_status'] ) {
			$parts[] = sprintf(
				/* translators: %s: contact status */
				__( 'Contact status: %s', 'emailsendx-sync' ),
				(string) $row['contact_status']
			);
		}
		return implode( ' · ', $parts );
	}

	/* ─── Users screen ─────────────────────────────────────────────── */

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_user_column( $columns ) {
		if ( ! current_user_can( 'list_users' ) || ! emailsendx_sync_is_configured() ) {
			return $columns;
		}
		$columns['emailsendx_health'] = __( 'EmailSendX', 'emailsendx-sync' );
		return $columns;
	}

	/**
	 * @param string $output  Existing output.
	 * @param string $column  Column key.
	 * @param int    $user_id User.
	 * @return string
	 */
	public static function render_user_column( $output, $column, $user_id ) {
		if ( 'emailsendx_health' !== $column ) {
			return $output;
		}
		$user = get_userdata( (int) $user_id );
		$row  = $user ? self::get_for_email( $user->user_email ) : null;
		if ( ! $row ) {
			return '<span class="esx-health esx-health-muted">—</span>';
		}
		$verdict = (string) $row['verdict'];
		return sprintf(
			'<span class="esx-health esx-health-%1$s" title="%2$s">%3$s</span>',
			esc_attr( self::tone( $verdict ) ),
			esc_attr( self::describe( $row ) ),
			esc_html( self::label( $verdict ) )
		);
	}

	/**
	 * Filter dropdown above the Users table.
	 *
	 * @param string $which top | bottom.
	 */
	public static function render_user_filter( $which = 'top' ) {
		if ( 'top' !== $which || ! emailsendx_sync_is_configured() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_VAR ] ) ) : '';
		$options = array(
			''        => __( 'EmailSendX: any', 'emailsendx-sync' ),
			'problem' => __( 'EmailSendX: needs cleanup', 'emailsendx-sync' ),
		);
		foreach ( self::VERDICTS as $v ) {
			$options[ $v ] = sprintf(
				/* translators: %s: verdict label */
				__( 'EmailSendX: %s', 'emailsendx-sync' ),
				self::label( $v )
			);
		}
		echo '<label class="screen-reader-text" for="esx-health-filter">' . esc_html__( 'Filter by EmailSendX email health', 'emailsendx-sync' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_VAR ) . '" id="esx-health-filter" style="float:none;margin:0 4px 0 8px;">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'emailsendx-sync' ), '', 'esx_health_go', false );
	}

	/**
	 * Apply the filter to the Users list query.
	 *
	 * @param WP_User_Query $query Query.
	 */
	public static function filter_user_query( $query ) {
		global $pagenow, $wpdb;
		if ( ! is_admin() || 'users.php' !== $pagenow || ! current_user_can( 'list_users' ) || ! self::is_installed() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$want = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_VAR ] ) ) : '';
		if ( '' === $want ) {
			return;
		}
		if ( 'problem' === $want ) {
			$verdicts = self::PROBLEM_VERDICTS;
		} elseif ( in_array( $want, self::VERDICTS, true ) ) {
			$verdicts = array( $want );
		} else {
			return;
		}
		$table = self::table_name();
		$in    = implode( ',', array_fill( 0, count( $verdicts ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.user_email IN (SELECT email FROM {$table} WHERE verdict IN ($in))", $verdicts );
	}

	/**
	 * Badge styles on users.php (the plugin stylesheet is not loaded there).
	 */
	public static function print_users_css() {
		?>
		<style>
			.column-emailsendx_health { width: 9em; }
			.esx-health { display:inline-block; padding:1px 8px; border-radius:999px; font-size:12px; line-height:18px; border:1px solid transparent; white-space:nowrap; }
			.esx-health-ok    { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
			.esx-health-warn  { background:#fffbeb; color:#b45309; border-color:#fde68a; }
			.esx-health-error { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
			.esx-health-muted { color:#8c8f94; border:0; padding:0; }
		</style>
		<?php
	}

	/* ─── Admin actions ────────────────────────────────────────────── */

	/**
	 * "Check now" / "Full refresh" button on the Sync tab.
	 */
	public static function handle_pull() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_PULL );

		$full   = ! empty( $_POST['full'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$result = self::run( $full );
		set_transient( self::FLASH_TRANSIENT, $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( EmailSendX_Admin::get_admin_url( 'sync' ) . '#esx-email-health' );
		exit;
	}

	/**
	 * CSV of every address EmailSendX will not send to.
	 */
	public static function handle_export() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'emailsendx-sync' ) );
		}
		check_admin_referer( self::NONCE_EXPORT );

		self::maybe_install();
		$table = self::table_name();
		$in    = implode( ',', array_fill( 0, count( self::PROBLEM_VERDICTS ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT email, user_id, verdict, contact_status, validation, validation_score, suppression_reason, validated_at FROM {$table} WHERE verdict IN ($in) ORDER BY verdict, email", self::PROBLEM_VERDICTS ), ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="emailsendx-needs-cleanup-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, array( 'email', 'wp_user_id', 'type', 'verdict', 'contact_status', 'validation', 'validation_score', 'suppression_reason', 'validated_at_utc' ) );
		foreach ( (array) $rows as $r ) {
			fputcsv(
				$fh,
				array(
					self::csv_safe( $r['email'] ),
					(int) $r['user_id'] > 0 ? (int) $r['user_id'] : '',
					(int) $r['user_id'] > 0 ? 'wp_user' : 'woo_guest',
					$r['verdict'],
					$r['contact_status'],
					$r['validation'],
					null === $r['validation_score'] ? '' : (int) $r['validation_score'],
					$r['suppression_reason'],
					(string) $r['validated_at'],
				)
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ─── Small helpers ────────────────────────────────────────────── */

	/**
	 * WooCommerce custom orders table (HPOS) in use?
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
	 * Stop spreadsheet formula injection in a CSV cell.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	protected static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $v : $v;
	}

	/**
	 * @param mixed $v   Value.
	 * @param int   $max Max length.
	 * @return string
	 */
	protected static function clip( $v, $max ) {
		return substr( sanitize_text_field( (string) $v ), 0, $max );
	}

	/**
	 * ISO time → MySQL UTC, or null.
	 *
	 * @param string $iso ISO 8601.
	 * @return string|null
	 */
	protected static function to_mysql( $iso ) {
		$ts = $iso ? strtotime( (string) $iso ) : false;
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
