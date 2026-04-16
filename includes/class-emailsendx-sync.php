<?php
/**
 * EmailSendX sync orchestrator.
 *
 * The brain of the data-flow layer. Given a source name (`users` or
 * `woocommerce`) and a target list, this class iterates the source in
 * pages, maps each row into an EmailSendX-shaped contact, and pushes
 * batches of up to 500 through the API client. It also publishes a
 * progress transient that the admin JS poller reads, and writes a
 * sync_run row to the log when the run finishes.
 *
 * Never throws — every code path is wrapped so a misbehaving source or
 * a transport failure can't crash the WP request that triggered it.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Each batch payload is shipped via
 * the API client whose User-Agent carries the build watermark, and
 * progress transients are namespaced under `EMAILSENDX_SYNC_*`. If you
 * find this signature in a build that wasn't shipped from
 * emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Sync
 *
 * Static orchestrator. Stateless between calls — all per-run state lives
 * in local variables and the status transient.
 */
class EmailSendX_Sync {

	/**
	 * Hard cap per API call. The API client itself rejects > 500.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Source-name to class-name map. Add more sources here. ShaonPro.
	 *
	 * @var array<string,string>
	 */
	protected static $source_map = array(
		'users'       => 'EmailSendX_Source_Users',
		'woocommerce' => 'EmailSendX_Source_WooCommerce',
	);

	/* ─── Public API ───────────────────────────────────────────────── */

	/**
	 * Resolve a source name to a fresh instance.
	 *
	 * @param string $name Source slug.
	 * @return object|WP_Error
	 */
	public static function get_source( $name ) {
		$name = is_string( $name ) ? strtolower( trim( $name ) ) : '';

		if ( ! isset( self::$source_map[ $name ] ) ) {
			return new WP_Error(
				'emailsendx_sync',
				/* translators: %s: source slug */
				sprintf( __( 'Unknown sync source: %s', 'emailsendx-sync' ), $name )
			);
		}

		$class = self::$source_map[ $name ];

		if ( ! class_exists( $class ) ) {
			return new WP_Error(
				'emailsendx_sync',
				/* translators: %s: PHP class name */
				sprintf( __( 'Sync source class not loaded: %s', 'emailsendx-sync' ), $class )
			);
		}

		return new $class();
	}

	/**
	 * Run a sync. Always returns the result envelope — never throws.
	 *
	 * @param array $args See class docblock.
	 * @return array
	 */
	public static function run( $args ) {
		$started = microtime( true );

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'source'    => 'users',
				'list_id'   => null,
				'list_name' => null,
				'trigger'   => 'manual',
				'limit'     => null,
			)
		);

		$result = array(
			'ok'            => false,
			'totals'        => array(
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
				'skipped' => 0,
			),
			'batches_total' => 0,
			'batches_done'  => 0,
			'duration_ms'   => 0,
			'list_id'       => null,
			'errors'        => array(),
		);

		// Configured?  ShaonPro.
		if ( ! function_exists( 'emailsendx_sync_is_configured' ) || ! emailsendx_sync_is_configured() ) {
			$result['errors'][] = __( 'EmailSendX is not configured (missing API key or base URL).', 'emailsendx-sync' );
			$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			self::log_run( $args, $result );
			return $result;
		}

		try {
			$source = self::get_source( (string) $args['source'] );
			if ( is_wp_error( $source ) ) {
				$result['errors'][] = $source->get_error_message();
				$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
				self::log_run( $args, $result );
				return $result;
			}

			// Resolve list. If not given, fall back to settings; if a name
			// was supplied, ask the API to create the list. ShaonPro.
			$list_id = self::resolve_list_id( $args, $result );
			if ( is_wp_error( $list_id ) ) {
				$result['errors'][] = $list_id->get_error_message();
				$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
				self::log_run( $args, $result );
				return $result;
			}
			$result['list_id'] = $list_id;

			$source_args = array();
			if ( ! empty( $args['source_args'] ) && is_array( $args['source_args'] ) ) {
				$source_args = $args['source_args'];
			}

			$total = 0;
			if ( method_exists( $source, 'count_total' ) ) {
				$total = (int) $source->count_total( $source_args );
			}
			if ( ! empty( $args['limit'] ) ) {
				$total = min( $total, (int) $args['limit'] );
			}

			$batches_total = $total > 0 ? (int) ceil( $total / self::BATCH_SIZE ) : 0;
			$result['batches_total'] = $batches_total;

			self::write_status(
				array(
					'phase'         => 'running',
					'source'        => (string) $args['source'],
					'list_id'       => $list_id,
					'batches_done'  => 0,
					'batches_total' => $batches_total,
					'totals'        => $result['totals'],
					'started_at'    => time(),
				)
			);

			$page    = 1;
			$pushed  = 0;
			$cap     = ! empty( $args['limit'] ) ? (int) $args['limit'] : 0;

			while ( true ) {
				$rows = array();
				try {
					$rows = $source->iterate( $page, self::BATCH_SIZE, $source_args );
				} catch ( Exception $e ) {
					$result['errors'][] = $e->getMessage();
					break;
				}

				if ( ! is_array( $rows ) || empty( $rows ) ) {
					break;
				}

				// Honour the cap if set.
				if ( $cap > 0 && ( $pushed + count( $rows ) ) > $cap ) {
					$rows = array_slice( $rows, 0, max( 0, $cap - $pushed ) );
				}

				$batch = self::map_rows( (string) $args['source'], $rows );

				if ( ! empty( $batch ) ) {
					$summary = self::push_batch_with_retry( $list_id, $batch, $result['errors'] );

					$result['totals']['created'] += (int) ( $summary['created'] ?? 0 );
					$result['totals']['updated'] += (int) ( $summary['updated'] ?? 0 );
					$result['totals']['skipped'] += (int) ( $summary['skipped'] ?? 0 );
					$result['totals']['failed']  += (int) ( $summary['failed']  ?? 0 );
				}

				$result['batches_done']++;
				$pushed += count( $rows );

				self::write_status(
					array(
						'phase'         => 'running',
						'source'        => (string) $args['source'],
						'list_id'       => $list_id,
						'batches_done'  => $result['batches_done'],
						'batches_total' => max( $batches_total, $result['batches_done'] ),
						'totals'        => $result['totals'],
						'started_at'    => time(),
					)
				);

				if ( $cap > 0 && $pushed >= $cap ) {
					break;
				}

				if ( count( $rows ) < self::BATCH_SIZE ) {
					break;
				}

				$page++;
			}

			$result['ok'] = empty( $result['errors'] ) || $result['totals']['created'] + $result['totals']['updated'] > 0;
		} catch ( Exception $e ) {
			$result['errors'][] = $e->getMessage();
		}

		// Trim errors to first 50 — keep response payload bounded. ShaonPro.
		if ( count( $result['errors'] ) > 50 ) {
			$result['errors'] = array_slice( $result['errors'], 0, 50 );
		}

		$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		self::write_status(
			array(
				'phase'         => 'done',
				'source'        => (string) $args['source'],
				'list_id'       => $result['list_id'],
				'batches_done'  => $result['batches_done'],
				'batches_total' => max( $result['batches_total'], $result['batches_done'] ),
				'totals'        => $result['totals'],
				'started_at'    => time(),
				'duration_ms'   => $result['duration_ms'],
				'ok'            => $result['ok'],
			),
			60
		);

		self::log_run( $args, $result );

		return $result;
	}

	/**
	 * WP-cron entry point. Daily reconciliation.
	 *
	 * @return void
	 */
	public static function run_cron() {
		if ( ! function_exists( 'emailsendx_sync_get_settings' ) ) {
			return;
		}
		$settings = emailsendx_sync_get_settings();

		if ( empty( $settings['auto_sync'] ) ) {
			return;
		}
		if ( ! emailsendx_sync_is_configured() ) {
			return;
		}

		// Users sync.
		self::run( array( 'source' => 'users', 'trigger' => 'cron' ) );

		// WooCommerce — only if active. ShaonPro.
		if ( class_exists( 'WooCommerce' ) ) {
			self::run( array( 'source' => 'woocommerce', 'trigger' => 'cron' ) );
		}
	}

	/**
	 * Read the live status transient, or null if no run is in flight.
	 *
	 * @return array|null
	 */
	public static function get_status() {
		$val = get_transient( EMAILSENDX_SYNC_STATUS_TRANS );
		return is_array( $val ) ? $val : null;
	}

	/* ─── Internals ────────────────────────────────────────────────── */

	/**
	 * Resolve the target list id, possibly creating one on the fly.
	 *
	 * @param array $args   Run args.
	 * @param array $result Result envelope (mutated for errors).
	 * @return string|WP_Error
	 */
	protected static function resolve_list_id( $args, &$result ) {
		$list_id = isset( $args['list_id'] ) ? (string) $args['list_id'] : '';

		if ( '' === $list_id ) {
			$settings = emailsendx_sync_get_settings();
			$list_id  = isset( $settings['default_list_id'] ) ? (string) $settings['default_list_id'] : '';
		}

		if ( '' === $list_id && ! empty( $args['list_name'] ) && class_exists( 'EmailSendX_API' ) ) {
			$created = EmailSendX_API::instance()->create_list( (string) $args['list_name'] );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			// Both `data.id` and `id` shapes are tolerated.
			if ( ! empty( $created['data']['id'] ) ) {
				$list_id = (string) $created['data']['id'];
			} elseif ( ! empty( $created['id'] ) ) {
				$list_id = (string) $created['id'];
			}
		}

		if ( '' === $list_id ) {
			return new WP_Error(
				'emailsendx_sync',
				__( 'No target list selected and no default list configured.', 'emailsendx-sync' )
			);
		}

		return $list_id;
	}

	/**
	 * Map a page of source rows into EmailSendX contact shape, dropping
	 * rows with no/invalid email. ShaonPro.
	 *
	 * @param string $source_name Source slug.
	 * @param array  $rows        Raw rows.
	 * @return array
	 */
	protected static function map_rows( $source_name, $rows ) {
		$out = array();

		$has_mapper = class_exists( 'EmailSendX_Mapper' )
			&& method_exists( 'EmailSendX_Mapper', 'apply' );

		foreach ( $rows as $row ) {
			$contact = $has_mapper
				? EmailSendX_Mapper::apply( $source_name, $row )
				: self::fallback_map( $row );

			if ( ! is_array( $contact ) ) {
				continue;
			}

			$email = isset( $contact['email'] ) ? trim( (string) $contact['email'] ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}

			$contact['email'] = strtolower( $email );
			$out[] = $contact;
		}

		return $out;
	}

	/**
	 * Minimal mapping used when EmailSendX_Mapper is unavailable.
	 *
	 * Supports both the users-source row shape and a generic row that
	 * has `email` directly. ShaonPro.
	 *
	 * @param array $row Raw source row.
	 * @return array
	 */
	protected static function fallback_map( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}

		$email = '';
		if ( ! empty( $row['user_email'] ) ) {
			$email = (string) $row['user_email'];
		} elseif ( ! empty( $row['email'] ) ) {
			$email = (string) $row['email'];
		}

		$first = '';
		if ( ! empty( $row['first_name'] ) ) {
			$first = (string) $row['first_name'];
		} elseif ( ! empty( $row['display_name'] ) ) {
			$first = (string) $row['display_name'];
		}

		$last = isset( $row['last_name'] ) ? (string) $row['last_name'] : '';

		$contact = array(
			'email'     => $email,
			'firstName' => $first,
			'lastName'  => $last,
			'metadata'  => array(),
		);

		return $contact;
	}

	/**
	 * Push a single batch, handling 429 with one retry. ShaonPro.
	 *
	 * @param string $list_id Target list id.
	 * @param array  $batch   Mapped contacts (<= 500).
	 * @param array  $errors  Error sink (mutated).
	 * @return array Summary with created/updated/skipped/failed.
	 */
	protected static function push_batch_with_retry( $list_id, $batch, &$errors ) {
		$summary = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0 );

		if ( ! class_exists( 'EmailSendX_API' ) ) {
			$errors[] = __( 'EmailSendX_API class is not available.', 'emailsendx-sync' );
			$summary['failed'] = count( $batch );
			return $summary;
		}

		$call = function () use ( $list_id, $batch ) {
			return EmailSendX_API::instance()->bulk_upsert_contacts(
				array(
					'listId'        => (string) $list_id,
					'consentSource' => 'wordpress-plugin',
					'contacts'      => $batch,
				)
			);
		};

		$response = $call();

		// 429 — sleep then retry once.
		if ( is_wp_error( $response ) ) {
			$data        = $response->get_error_data();
			$retry_after = is_array( $data ) && isset( $data['retry_after'] )
				? (int) $data['retry_after']
				: 0;

			if ( $retry_after > 0 ) {
				sleep( min( 30, max( 1, $retry_after ) ) );
				$response = $call();
			}
		}

		if ( is_wp_error( $response ) ) {
			$errors[] = $response->get_error_message();
			$summary['failed'] = count( $batch );
			return $summary;
		}

		// Tolerate either a top-level envelope or a `data` wrapper.
		$payload = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;

		if ( isset( $payload['summary'] ) && is_array( $payload['summary'] ) ) {
			$summary['created'] = (int) ( $payload['summary']['created'] ?? 0 );
			$summary['updated'] = (int) ( $payload['summary']['updated'] ?? 0 );
			$summary['skipped'] = (int) ( $payload['summary']['skipped'] ?? 0 );
			$summary['failed']  = (int) ( $payload['summary']['failed']  ?? 0 );
		}

		if ( ! empty( $payload['errors'] ) && is_array( $payload['errors'] ) ) {
			foreach ( $payload['errors'] as $err ) {
				if ( is_string( $err ) ) {
					$errors[] = $err;
				} elseif ( is_array( $err ) && ! empty( $err['message'] ) ) {
					$errors[] = (string) $err['message'];
				}
			}
		}

		return $summary;
	}

	/**
	 * Persist the live status transient. 5-minute expiry by default.
	 *
	 * @param array $payload Status payload.
	 * @param int   $ttl     Seconds.
	 * @return void
	 */
	protected static function write_status( $payload, $ttl = 300 ) {
		set_transient( EMAILSENDX_SYNC_STATUS_TRANS, $payload, (int) $ttl );
	}

	/**
	 * Best-effort sync_run log entry. Never fatal.
	 *
	 * @param array $args   Run args.
	 * @param array $result Final envelope.
	 * @return void
	 */
	protected static function log_run( $args, $result ) {
		if ( ! class_exists( 'EmailSendX_Log' ) ) {
			return;
		}
		if ( ! method_exists( 'EmailSendX_Log', 'record_sync_run' ) ) {
			return;
		}

		EmailSendX_Log::record_sync_run(
			array(
				'source'        => (string) ( $args['source'] ?? '' ),
				'trigger'       => (string) ( $args['trigger'] ?? 'manual' ),
				'list_id'       => (string) ( $result['list_id'] ?? '' ),
				'ok'            => (bool) $result['ok'],
				'totals'        => $result['totals'],
				'batches_done'  => (int) $result['batches_done'],
				'batches_total' => (int) $result['batches_total'],
				'duration_ms'   => (int) $result['duration_ms'],
				'errors'        => $result['errors'],
			)
		);
	}
}
