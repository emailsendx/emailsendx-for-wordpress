<?php
/**
 * Log tab view.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=log`.
 * Shows summary stat cards + a paginated table of recent sync runs.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Reads from `EmailSendX_Log` —
 * if you find this file in a build that wasn't shipped from
 * emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

$per_page = 50;
$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$offset   = ( $paged - 1 ) * $per_page;

$rows    = array();
$summary = array();
if ( class_exists( 'EmailSendX_Log' ) ) {
	if ( method_exists( 'EmailSendX_Log', 'get_recent' ) ) {
		$rows = (array) EmailSendX_Log::get_recent( $per_page, $offset );
	}
	if ( method_exists( 'EmailSendX_Log', 'get_summary' ) ) {
		$summary = (array) EmailSendX_Log::get_summary();
	}
}

$synced_30d  = isset( $summary['synced_last_30_days'] ) ? (int) $summary['synced_last_30_days'] : 0;
$errors_24h  = isset( $summary['errors_last_24h'] ) ? (int) $summary['errors_last_24h'] : 0;
$last_time   = isset( $summary['last_sync_time'] ) ? (int) $summary['last_sync_time'] : 0;
if ( 0 === $last_time && ! empty( $summary['last_sync_at'] ) ) {
	$last_time = (int) strtotime( (string) $summary['last_sync_at'] . ' UTC' );
}

// ShaonPro — pretty stat-card row.
?>

<div class="esx-stats">
	<div class="esx-stat">
		<span class="esx-stat-label"><?php echo esc_html__( 'Synced last 30 days', 'emailsendx-sync' ); ?></span>
		<span class="esx-stat-value"><?php echo esc_html( number_format_i18n( $synced_30d ) ); ?></span>
	</div>
	<div class="esx-stat">
		<span class="esx-stat-label"><?php echo esc_html__( 'Errors last 24h', 'emailsendx-sync' ); ?></span>
		<span class="esx-stat-value <?php echo $errors_24h > 0 ? 'esx-stat-warn' : ''; ?>">
			<?php echo esc_html( number_format_i18n( $errors_24h ) ); ?>
		</span>
	</div>
	<div class="esx-stat">
		<span class="esx-stat-label"><?php echo esc_html__( 'Last sync', 'emailsendx-sync' ); ?></span>
		<span class="esx-stat-value">
			<?php
			if ( $last_time > 0 ) {
				echo esc_html(
					sprintf(
						/* translators: %s: human-readable time diff */
						__( '%s ago', 'emailsendx-sync' ),
						human_time_diff( $last_time, current_time( 'timestamp' ) )
					)
				);
			} else {
				echo esc_html__( 'Never', 'emailsendx-sync' );
			}
			?>
		</span>
	</div>
</div>

<div class="esx-card">
	<div class="esx-card-head">
		<h2 class="esx-card-title"><?php echo esc_html__( 'Recent syncs', 'emailsendx-sync' ); ?></h2>
		<p class="esx-card-sub"><?php echo esc_html__( 'The last few hundred sync runs, newest first.', 'emailsendx-sync' ); ?></p>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<div class="esx-empty">
			<p><strong><?php echo esc_html__( 'No syncs yet.', 'emailsendx-sync' ); ?></strong>
			<?php echo esc_html__( 'Run your first sync from the Sync tab.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( EmailSendX_Admin::get_admin_url( 'sync' ) ); ?>">
				<?php echo esc_html__( 'Go to Sync', 'emailsendx-sync' ); ?>
			</a>
		</div>
	<?php else : ?>
		<ul class="esx-activity">
			<?php foreach ( $rows as $row ) :
				$row     = (array) $row;
				$when    = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
				$source  = isset( $row['source'] ) ? (string) $row['source'] : '';
				$list    = isset( $row['list_name'] ) ? (string) $row['list_name'] : ( isset( $row['list_id'] ) ? (string) $row['list_id'] : '' );
				$ms      = isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0;
				$status  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : 'ok';

				switch ( $status ) {
					case 'error':
					case 'failed':
						$pill_class = 'esx-pill-error';
						$pill_label = __( 'Error', 'emailsendx-sync' );
						$icon_class = 'esx-activity-icon-error';
						$icon_svg   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm3.54 13.54a1 1 0 01-1.42 1.42L12 14.83l-2.12 2.13a1 1 0 11-1.42-1.42L10.59 13.4 8.46 11.3a1 1 0 011.42-1.42L12 12l2.12-2.12a1 1 0 011.42 1.42L13.41 13.4l2.13 2.14z" fill="currentColor"/></svg>';
						break;
					case 'warn':
					case 'warning':
					case 'partial':
						$pill_class = 'esx-pill-warn';
						$pill_label = __( 'Partial', 'emailsendx-sync' );
						$icon_class = 'esx-activity-icon-warn';
						$icon_svg   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2L1 21h22L12 2zm0 6l7.53 13H4.47L12 8zm-1 4v4h2v-4h-2zm0 6v2h2v-2h-2z" fill="currentColor"/></svg>';
						break;
					case 'api_call':
					case 'info':
						$pill_class = 'esx-pill-info';
						$pill_label = __( 'API call', 'emailsendx-sync' );
						$icon_class = 'esx-activity-icon-info';
						$icon_svg   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M17.65 6.35A7.95 7.95 0 0012 4V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4a8 8 0 1013.65-6.65z" fill="currentColor"/></svg>';
						break;
					default:
						$pill_class = 'esx-pill-ok';
						$pill_label = __( 'OK', 'emailsendx-sync' );
						$icon_class = 'esx-activity-icon-ok';
						$icon_svg   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" fill="currentColor"/></svg>';
				}

				$sentence = '';
				if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'humanize_row' ) ) {
					$sentence = (string) EmailSendX_Log::humanize_row( $row );
				}
				if ( '' === $sentence ) {
					$sentence = sprintf(
						/* translators: 1: source, 2: list */
						__( '%1$s → %2$s', 'emailsendx-sync' ),
						$source !== '' ? $source : __( 'sync', 'emailsendx-sync' ),
						$list !== '' ? $list : __( 'default list', 'emailsendx-sync' )
					);
				}

				$when_ts    = $when ? (int) strtotime( $when . ' UTC' ) : 0;
				$when_rel   = $when_ts > 0 ? human_time_diff( $when_ts, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'emailsendx-sync' ) : '';
				$when_full  = $when ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) : '';

				$sub_parts = array();
				if ( $when_rel ) { $sub_parts[] = $when_rel; }
				if ( $source !== '' ) { $sub_parts[] = $source; }
				if ( $list !== '' ) { $sub_parts[] = $list; }
				$sub_line = implode( ' · ', $sub_parts );

				$dur_human = $ms > 0 ? sprintf( '%.1fs', $ms / 1000 ) : '';
				?>
				<li class="esx-activity-row">
					<span class="esx-activity-icon <?php echo esc_attr( $icon_class ); ?>"><?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div class="esx-activity-body">
						<div class="esx-activity-title"><?php echo esc_html( $sentence ); ?></div>
						<?php if ( $sub_line ) : ?>
							<div class="esx-activity-sub" title="<?php echo esc_attr( $when_full ); ?>"><?php echo esc_html( $sub_line ); ?></div>
						<?php endif; ?>
					</div>
					<div class="esx-activity-meta">
						<span class="esx-pill <?php echo esc_attr( $pill_class ); ?>">
							<?php echo esc_html( $pill_label ); ?>
						</span>
						<?php if ( $dur_human ) : ?>
							<span class="esx-activity-duration"><?php echo esc_html( $dur_human ); ?></span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<details class="esx-raw-table">
			<summary><?php echo esc_html__( 'View raw table', 'emailsendx-sync' ); ?></summary>
			<table class="esx-table widefat">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'When', 'emailsendx-sync' ); ?></th>
						<th><?php echo esc_html__( 'Source', 'emailsendx-sync' ); ?></th>
						<th><?php echo esc_html__( 'List', 'emailsendx-sync' ); ?></th>
						<th class="esx-num"><?php echo esc_html__( 'Created', 'emailsendx-sync' ); ?></th>
						<th class="esx-num"><?php echo esc_html__( 'Updated', 'emailsendx-sync' ); ?></th>
						<th class="esx-num"><?php echo esc_html__( 'Failed', 'emailsendx-sync' ); ?></th>
						<th class="esx-num"><?php echo esc_html__( 'Duration', 'emailsendx-sync' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'emailsendx-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$row        = (array) $row;
						$when_r     = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
						$source_r   = isset( $row['source'] ) ? (string) $row['source'] : '';
						$list_r     = isset( $row['list_name'] ) ? (string) $row['list_name'] : ( isset( $row['list_id'] ) ? (string) $row['list_id'] : '' );
						$created_r  = isset( $row['created'] ) ? (int) $row['created'] : 0;
						$updated_r  = isset( $row['updated'] ) ? (int) $row['updated'] : 0;
						$failed_r   = isset( $row['failed'] ) ? (int) $row['failed'] : 0;
						$ms_r       = isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0;
						$status_r   = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : 'ok';
						$when_h     = $when_r ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when_r ) : '—';
						$dur_h      = $ms_r > 0 ? sprintf( '%.1fs', $ms_r / 1000 ) : '—';
						?>
						<tr>
							<td><?php echo esc_html( $when_h ); ?></td>
							<td><?php echo esc_html( $source_r ); ?></td>
							<td><?php echo esc_html( $list_r ); ?></td>
							<td class="esx-num"><?php echo esc_html( number_format_i18n( $created_r ) ); ?></td>
							<td class="esx-num"><?php echo esc_html( number_format_i18n( $updated_r ) ); ?></td>
							<td class="esx-num"><?php echo esc_html( number_format_i18n( $failed_r ) ); ?></td>
							<td class="esx-num"><?php echo esc_html( $dur_h ); ?></td>
							<td><?php echo esc_html( $status_r ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>

		<?php
		// Lightweight pager — we only know there's a next page if we
		// got a full page back. ShaonPro.
		$has_prev = $paged > 1;
		$has_next = count( $rows ) === $per_page;
		if ( $has_prev || $has_next ) :
			?>
			<div class="esx-pager">
				<?php if ( $has_prev ) : ?>
					<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, EmailSendX_Admin::get_admin_url( 'log' ) ) ); ?>">
						<?php echo esc_html__( '← Newer', 'emailsendx-sync' ); ?>
					</a>
				<?php endif; ?>
				<span class="esx-pager-page">
					<?php echo esc_html( sprintf( /* translators: %d: page number */ __( 'Page %d', 'emailsendx-sync' ), $paged ) ); ?>
				</span>
				<?php if ( $has_next ) : ?>
					<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, EmailSendX_Admin::get_admin_url( 'log' ) ) ); ?>">
						<?php echo esc_html__( 'Older →', 'emailsendx-sync' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
