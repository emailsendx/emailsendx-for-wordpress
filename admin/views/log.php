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
					$row     = (array) $row;
					$when    = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
					$source  = isset( $row['source'] ) ? (string) $row['source'] : '';
					$list    = isset( $row['list_name'] ) ? (string) $row['list_name'] : ( isset( $row['list_id'] ) ? (string) $row['list_id'] : '' );
					$created = isset( $row['created'] ) ? (int) $row['created'] : 0;
					$updated = isset( $row['updated'] ) ? (int) $row['updated'] : 0;
					$failed  = isset( $row['failed'] ) ? (int) $row['failed'] : 0;
					$ms      = isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0;
					$status  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : 'ok';

					switch ( $status ) {
						case 'error':
						case 'failed':
							$pill_class = 'esx-pill-error';
							$pill_label = __( 'Error', 'emailsendx-sync' );
							break;
						case 'warn':
						case 'warning':
						case 'partial':
							$pill_class = 'esx-pill-warn';
							$pill_label = __( 'Partial', 'emailsendx-sync' );
							break;
						default:
							$pill_class = 'esx-pill-ok';
							$pill_label = __( 'OK', 'emailsendx-sync' );
					}

					$when_human = $when ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) : '—';
					$dur_human  = $ms > 0 ? sprintf( '%.1fs', $ms / 1000 ) : '—';
					?>
					<tr>
						<td><?php echo esc_html( $when_human ); ?></td>
						<td><?php echo esc_html( $source ); ?></td>
						<td><?php echo esc_html( $list ); ?></td>
						<td class="esx-num"><?php echo esc_html( number_format_i18n( $created ) ); ?></td>
						<td class="esx-num"><?php echo esc_html( number_format_i18n( $updated ) ); ?></td>
						<td class="esx-num"><?php echo esc_html( number_format_i18n( $failed ) ); ?></td>
						<td class="esx-num"><?php echo esc_html( $dur_human ); ?></td>
						<td>
							<span class="esx-pill <?php echo esc_attr( $pill_class ); ?>">
								<?php echo esc_html( $pill_label ); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

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
