<?php
/**
 * EmailSendX admin notices/warnings system.
 *
 * A tiny, opinionated flash+persistent notice store. Consumers call the
 * static public API to push notices; this class writes them to a single
 * autoloaded option (`emailsendx_sync_notices`), renders them via
 * `admin_notices`, and honours per-user dismissal (user_meta) for 30 days.
 *
 * Messages are stored as plain text and escaped on render — callers MUST
 * NOT pass HTML.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Option key and meta key prefixes
 * are part of the plugin's stable contract. If you find this file in a
 * build that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

/**
 * Class EmailSendX_Notices
 *
 * All-static public API. The constructor only wires WP hooks — state
 * lives in the `emailsendx_sync_notices` option.
 */
class EmailSendX_Notices {

	/** Option key (autoloaded array). */
	const OPTION_KEY = 'emailsendx_sync_notices';

	/** Maximum stored entries — oldest dropped when exceeded. */
	const MAX_ENTRIES = 20;

	/** Per-user dismissal TTL in seconds (30 days). */
	const DISMISS_TTL = 2592000;

	/**
	 * Wire hooks. Safe to instantiate outside of admin — gates internally.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_emailsendx_dismiss_notice', array( __CLASS__, 'ajax_dismiss' ) );
	}

	/* ─── Public API ───────────────────────────────────────────────── */

	/**
	 * Add (or dedup-update) a notice.
	 *
	 * @param string $key     Stable id.
	 * @param string $type    info|success|warning|error.
	 * @param string $message Plain text, already translated. No HTML.
	 * @param array  $opts    actions, scope, ttl, dismissible, meta.
	 * @return void
	 */
	public static function add( $key, $type, $message, $opts = array() ) {
		$key  = self::sanitize_key( $key );
		$type = self::sanitize_type( $type );
		if ( '' === $key ) {
			return;
		}

		$opts = is_array( $opts ) ? $opts : array();
		$ttl  = array_key_exists( 'ttl', $opts ) ? (int) $opts['ttl'] : 900;
		$now  = time();

		$entry = array(
			'key'         => $key,
			'type'        => $type,
			'message'     => (string) $message,
			'actions'     => self::sanitize_actions( isset( $opts['actions'] ) ? $opts['actions'] : array() ),
			'scope'       => ( isset( $opts['scope'] ) && 'global' === $opts['scope'] ) ? 'global' : 'plugin',
			'created_at'  => $now,
			'expires_at'  => $ttl > 0 ? ( $now + $ttl ) : 0,
			'dismissible' => ! isset( $opts['dismissible'] ) ? true : (bool) $opts['dismissible'],
			'meta'        => isset( $opts['meta'] ) && is_array( $opts['meta'] ) ? $opts['meta'] : array(),
		);

		$all     = self::read_all();
		$updated = false;

		foreach ( $all as $i => $existing ) {
			if ( ! is_array( $existing ) || ! isset( $existing['key'] ) ) {
				continue;
			}
			if ( $existing['key'] !== $key ) {
				continue;
			}
			$still_live = empty( $existing['expires_at'] ) || (int) $existing['expires_at'] > $now;
			if ( $still_live ) {
				$all[ $i ]['message']    = $entry['message'];
				$all[ $i ]['meta']       = $entry['meta'];
				$all[ $i ]['expires_at'] = $entry['expires_at'];
				$all[ $i ]['type']       = $entry['type'];
				$all[ $i ]['actions']    = $entry['actions'];
				$updated                  = true;
				break;
			}
			// Expired entry with same key — drop, will be re-added below.
			unset( $all[ $i ] );
		}

		if ( ! $updated ) {
			$all[] = $entry;
		}

		$all = array_values( $all );

		// Cap stored notices — drop oldest by created_at.
		if ( count( $all ) > self::MAX_ENTRIES ) {
			usort(
				$all,
				static function ( $a, $b ) {
					return (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 );
				}
			);
			$all = array_slice( $all, -self::MAX_ENTRIES );
			$all = array_values( $all );
		}

		self::write_all( $all );
	}

	/**
	 * Remove a notice by key.
	 *
	 * @param string $key Notice key.
	 * @return void
	 */
	public static function clear( $key ) {
		$key = self::sanitize_key( $key );
		if ( '' === $key ) {
			return;
		}
		$all   = self::read_all();
		$kept  = array();
		$dirty = false;
		foreach ( $all as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'] ) && $entry['key'] === $key ) {
				$dirty = true;
				continue;
			}
			$kept[] = $entry;
		}
		if ( $dirty ) {
			self::write_all( array_values( $kept ) );
		}
	}

	/**
	 * Remove every notice whose key starts with $prefix.
	 *
	 * @param string $prefix Key prefix.
	 * @return void
	 */
	public static function clear_all_matching( $prefix ) {
		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return;
		}
		$all   = self::read_all();
		$kept  = array();
		$dirty = false;
		foreach ( $all as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'] ) && 0 === strpos( (string) $entry['key'], $prefix ) ) {
				$dirty = true;
				continue;
			}
			$kept[] = $entry;
		}
		if ( $dirty ) {
			self::write_all( array_values( $kept ) );
		}
	}

	/**
	 * Return the notice array filtered for render.
	 *
	 * @param string $scope_hint 'plugin' or 'global'.
	 * @return array
	 */
	public static function get_for_render( $scope_hint = 'plugin' ) {
		$scope_hint = ( 'global' === $scope_hint ) ? 'global' : 'plugin';
		$all        = self::read_all();
		$now        = time();
		$user_id    = get_current_user_id();
		$out        = array();

		foreach ( $all as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) ) {
				continue;
			}
			// Expired?
			if ( ! empty( $entry['expires_at'] ) && (int) $entry['expires_at'] <= $now ) {
				continue;
			}
			// Scope mismatch.
			$scope = isset( $entry['scope'] ) ? $entry['scope'] : 'plugin';
			if ( 'plugin' === $scope && 'plugin' !== $scope_hint ) {
				continue;
			}
			// User-dismissed within TTL.
			if ( $user_id && ! empty( $entry['dismissible'] ) ) {
				$dismissed_at = (int) get_user_meta( $user_id, self::dismiss_meta_key( $entry['key'] ), true );
				if ( $dismissed_at > 0 && ( $now - $dismissed_at ) < self::DISMISS_TTL ) {
					continue;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Record a user's dismissal timestamp.
	 *
	 * @param string $key     Notice key.
	 * @param int    $user_id User id.
	 * @return void
	 */
	public static function dismiss( $key, $user_id ) {
		$key     = self::sanitize_key( $key );
		$user_id = (int) $user_id;
		if ( '' === $key || $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::dismiss_meta_key( $key ), time() );
	}

	/**
	 * `admin_notices` handler.
	 */
	public static function render() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$is_plugin_page = self::is_plugin_admin_page();
		$scope_hint     = $is_plugin_page ? 'plugin' : 'global';
		$entries        = self::get_for_render( $scope_hint );

		if ( empty( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			self::render_entry( $entry );
		}
	}

	/**
	 * `wp_ajax_emailsendx_dismiss_notice` handler.
	 */
	public static function ajax_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'emailsendx-sync' ) ), 403 );
		}
		check_ajax_referer( 'emailsendx_sync_ajax' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Not authenticated.', 'emailsendx-sync' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing notice key.', 'emailsendx-sync' ) ), 400 );
		}

		self::dismiss( $key, $user_id );
		wp_send_json_success( array( 'key' => $key ) );
	}

	/**
	 * Option installer — no-op since the option autocreates on first add.
	 */
	public static function install() {
		// No schema to provision. ShaonPro.
	}

	/**
	 * Wipe the notice option on uninstall.
	 */
	public static function uninstall() {
		delete_option( self::OPTION_KEY );
	}

	/* ─── Internals ────────────────────────────────────────────────── */

	/**
	 * Render one notice entry.
	 *
	 * @param array $entry Sanitised notice entry.
	 */
	protected static function render_entry( $entry ) {
		$type        = self::sanitize_type( isset( $entry['type'] ) ? $entry['type'] : 'info' );
		$key         = isset( $entry['key'] ) ? (string) $entry['key'] : '';
		$message     = isset( $entry['message'] ) ? (string) $entry['message'] : '';
		$dismissible = ! empty( $entry['dismissible'] );
		$actions     = isset( $entry['actions'] ) && is_array( $entry['actions'] ) ? $entry['actions'] : array();
		$meta        = isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : array();

		$data_attrs = '';
		if ( isset( $meta['retry_after'] ) ) {
			$secs        = max( 0, (int) $meta['retry_after'] );
			$data_attrs .= ' data-esx-countdown="' . esc_attr( (string) $secs ) . '"';
		}

		?>
		<div class="esx-notice esx-notice-<?php echo esc_attr( $type ); ?>" data-esx-notice-key="<?php echo esc_attr( $key ); ?>"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above. ?>>
			<span class="esx-notice-icon" aria-hidden="true"><?php echo self::icon_svg( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
			<div class="esx-notice-body">
				<p class="esx-notice-message"><?php echo esc_html( $message ); ?></p>
				<?php if ( ! empty( $actions ) ) : ?>
					<div class="esx-notice-actions">
						<?php foreach ( $actions as $action ) : ?>
							<?php if ( empty( $action['url'] ) || empty( $action['label'] ) ) { continue; } ?>
							<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $dismissible ) : ?>
				<button type="button" class="esx-notice-dismiss" aria-label="<?php echo esc_attr__( 'Dismiss', 'emailsendx-sync' ); ?>" data-esx-action="dismiss-notice">
					<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4.22 4.22a.75.75 0 0 1 1.06 0L8 6.94l2.72-2.72a.75.75 0 1 1 1.06 1.06L9.06 8l2.72 2.72a.75.75 0 1 1-1.06 1.06L8 9.06l-2.72 2.72a.75.75 0 0 1-1.06-1.06L6.94 8 4.22 5.28a.75.75 0 0 1 0-1.06Z"/></svg>
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * SVG markup per notice type.
	 *
	 * @param string $type info|success|warning|error.
	 * @return string
	 */
	protected static function icon_svg( $type ) {
		switch ( $type ) {
			case 'success':
				return '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm3.78-10.28a.75.75 0 0 0-1.06-1.06L9 10.38 7.28 8.66a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.06 0l4.25-4.25Z"/></svg>';
			case 'warning':
				return '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M9.3 2.7a.8.8 0 0 1 1.4 0l7.3 13a.8.8 0 0 1-.7 1.2H2.7a.8.8 0 0 1-.7-1.2l7.3-13ZM10 8v4m0 2.25v.01"/></svg>';
			case 'error':
				return '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 3.25a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0V6a.75.75 0 0 1 .75-.75ZM10 13.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>';
			case 'info':
			default:
				return '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm.75 4.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM9.25 9a.75.75 0 0 1 1.5 0v5a.75.75 0 0 1-1.5 0V9Z"/></svg>';
		}
	}

	/**
	 * Whether the current admin screen is one of our plugin pages.
	 *
	 * @return bool
	 */
	protected static function is_plugin_admin_page() {
		if ( ! class_exists( 'EmailSendX_Admin' ) ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ( EmailSendX_Admin::MENU_SLUG === $page );
	}

	/**
	 * Sanitize a notice type, falling back to 'info'.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	protected static function sanitize_type( $type ) {
		$type    = is_string( $type ) ? strtolower( $type ) : '';
		$allowed = array( 'info', 'success', 'warning', 'error' );
		return in_array( $type, $allowed, true ) ? $type : 'info';
	}

	/**
	 * Sanitize a notice key — alnum + _ + - only.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	protected static function sanitize_key( $key ) {
		$key = is_string( $key ) ? $key : '';
		$key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );
		return (string) substr( (string) $key, 0, 64 );
	}

	/**
	 * Normalise and cap the actions array to <=2 entries.
	 *
	 * @param mixed $actions Raw value.
	 * @return array
	 */
	protected static function sanitize_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return array();
		}
		$out = array();
		foreach ( $actions as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$label = isset( $a['label'] ) ? (string) $a['label'] : '';
			$url   = isset( $a['url'] ) ? (string) $a['url'] : '';
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$out[] = array( 'label' => $label, 'url' => $url );
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Build the hashed user_meta key for a dismissal record.
	 *
	 * @param string $key Notice key.
	 * @return string
	 */
	protected static function dismiss_meta_key( $key ) {
		return 'esx_dismissed_' . substr( md5( (string) $key ), 0, 16 );
	}

	/**
	 * Read the notices option with a defensive fallback.
	 *
	 * @return array
	 */
	protected static function read_all() {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Write the notices option — errors are swallowed (non-fatal).
	 *
	 * @param array $entries Normalised entry array.
	 * @return void
	 */
	protected static function write_all( $entries ) {
		if ( ! is_array( $entries ) ) {
			return;
		}
		$ok = update_option( self::OPTION_KEY, array_values( $entries ), true );
		if ( false === $ok && function_exists( 'error_log' ) ) {
			// Non-fatal — swallow and carry on. ShaonPro.
			error_log( 'EmailSendX_Notices: failed to persist notices option.' );
		}
	}
}
