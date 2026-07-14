<?php
/**
 * Integrations tab view.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=integrations`.
 * Lists every page-builder EmailSendX plugs into (name, logo, live
 * status) from {@see EmailSendX_Integrations::get_integrations()}.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. If you find this file in a build
 * that wasn't shipped from emailsendx.com, the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

$integrations = class_exists( 'EmailSendX_Integrations' ) ? EmailSendX_Integrations::get_integrations() : array();
$active_count = class_exists( 'EmailSendX_Integrations' ) ? EmailSendX_Integrations::active_count() : 0;
?>

<div class="esx-card esx-card-head">
	<div>
		<h2 class="esx-card-title"><?php echo esc_html__( 'Builder integrations', 'emailsendx-sync' ); ?></h2>
		<p class="esx-card-sub">
			<?php echo esc_html__( 'Add EmailSendX forms and newsletter boxes to your pages using the builder you already use. More builders are on the way.', 'emailsendx-sync' ); ?>
		</p>
	</div>
	<span class="esx-pill esx-pill-ok">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of active integrations. */
				_n( '%d active', '%d active', $active_count, 'emailsendx-sync' ),
				$active_count
			)
		);
		?>
	</span>
</div>

<div class="esx-int-grid">
	<?php foreach ( $integrations as $int ) : ?>
		<?php
		$status   = EmailSendX_Integrations::status( $int );
		$name     = isset( $int['name'] ) ? (string) $int['name'] : '';
		$logo     = isset( $int['logo'] ) ? (string) $int['logo'] : '';
		$monogram = isset( $int['monogram'] ) ? (string) $int['monogram'] : mb_substr( $name, 0, 1 );
		$color    = isset( $int['color'] ) ? (string) $int['color'] : '#4E8CFF';
		$version  = isset( $int['version'] ) ? (string) $int['version'] : '';
		$url      = isset( $int['url'] ) ? (string) $int['url'] : '';
		$is_active = 'active' === $status['state'];
		?>
		<div class="esx-int-card <?php echo $is_active ? 'esx-int-card-active' : ''; ?>">
			<div class="esx-int-head">
				<?php if ( '' !== $logo ) : ?>
					<span class="esx-int-logo esx-int-logo-img">
						<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
					</span>
				<?php else : ?>
					<span class="esx-int-logo" style="background: <?php echo esc_attr( $color ); ?>;">
						<?php echo esc_html( strtoupper( $monogram ) ); ?>
					</span>
				<?php endif; ?>

				<div class="esx-int-title">
					<span class="esx-int-name"><?php echo esc_html( $name ); ?></span>
					<?php if ( '' !== $version ) : ?>
						<span class="esx-int-version"><?php echo esc_html( 'v' . $version ); ?></span>
					<?php endif; ?>
				</div>

				<span class="esx-pill <?php echo esc_attr( $status['pill'] ); ?> esx-int-status">
					<?php echo esc_html( $status['label'] ); ?>
				</span>
			</div>

			<p class="esx-int-desc"><?php echo esc_html( isset( $int['description'] ) ? $int['description'] : '' ); ?></p>

			<div class="esx-int-foot">
				<?php if ( $is_active ) : ?>
					<span class="esx-int-hint">
						<?php echo esc_html__( 'Look for “EmailSendX Form” and “EmailSendX Newsletter” in the Add Element panel.', 'emailsendx-sync' ); ?>
					</span>
				<?php elseif ( '' !== $url ) : ?>
					<a class="esx-int-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html__( 'Learn more', 'emailsendx-sync' ); ?> &rarr;
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<div class="esx-card esx-card-quiet">
	<p class="esx-card-sub" style="margin:0;">
		<?php echo esc_html__( 'Want a builder that isn’t listed yet? It’s probably on our roadmap — and every integration uses the same EmailSendX shortcodes under the hood, so you can always paste those directly.', 'emailsendx-sync' ); ?>
	</p>
</div>
