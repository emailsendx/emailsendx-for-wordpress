<?php
/**
 * Forms tab view.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=forms`.
 * Explains the [emailsendx_form] shortcode / builder element and lists
 * the workspace's forms with a ready-to-paste snippet for each.
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

$is_ready = emailsendx_sync_is_configured();

if ( ! $is_ready ) :
	?>
	<div class="esx-card esx-card-empty">
		<div class="esx-empty">
			<h2 class="esx-empty-title"><?php echo esc_html__( 'Connect EmailSendX first', 'emailsendx-sync' ); ?></h2>
			<p class="esx-empty-sub"><?php echo esc_html__( 'Add your API key on the Settings tab to load your forms here.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( EmailSendX_Admin::get_admin_url( 'settings' ) ); ?>">
				<?php echo esc_html__( 'Go to Settings', 'emailsendx-sync' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
endif;

// ── Load the workspace's forms (best-effort). ──
$forms      = array();
$load_error = '';
if ( class_exists( 'EmailSendX_API' ) ) {
	$res = EmailSendX_API::instance()->get_forms( 1, 100 );
	if ( is_wp_error( $res ) ) {
		$load_error = $res->get_error_message();
	} elseif ( is_array( $res ) && isset( $res['data'] ) && is_array( $res['data'] ) ) {
		$forms = $res['data'];
	}
}

$settings  = emailsendx_sync_get_settings();
$dashboard = isset( $settings['api_base'] ) ? rtrim( (string) $settings['api_base'], '/' ) : EMAILSENDX_SYNC_DEFAULT_API_BASE;
if ( ! preg_match( '#^https?://#i', $dashboard ) ) {
	$dashboard = 'https://' . $dashboard;
}
?>

<div class="esx-card esx-card-head">
	<div>
		<h2 class="esx-card-title"><?php echo esc_html__( 'Opt-in forms', 'emailsendx-sync' ); ?></h2>
		<p class="esx-card-sub">
			<?php echo esc_html__( 'Embed any EmailSendX form on your site. Fields, double opt-in and spam protection all come from the form itself.', 'emailsendx-sync' ); ?>
		</p>
	</div>
	<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( $dashboard ); ?>" target="_blank" rel="noopener">
		<?php echo esc_html__( 'Build a form ↗', 'emailsendx-sync' ); ?>
	</a>
</div>

<div class="esx-card">
	<h3 class="esx-card-title"><?php echo esc_html__( 'Two ways to add a form', 'emailsendx-sync' ); ?></h3>
	<p class="esx-card-sub"><?php echo esc_html__( 'With a page builder: drop in the “EmailSendX Form” element and pick your form. Anywhere else: paste the shortcode.', 'emailsendx-sync' ); ?></p>
	<p><code class="esx-code">[emailsendx_form id="YOUR_FORM_ID"]</code></p>
</div>

<?php if ( '' !== $load_error ) : ?>
	<div class="esx-card esx-card-quiet">
		<p class="esx-card-sub" style="margin:0;">
			<?php echo esc_html__( 'Couldn’t load your forms right now. You can still paste the shortcode above with a form ID from your dashboard.', 'emailsendx-sync' ); ?>
		</p>
	</div>
<?php elseif ( empty( $forms ) ) : ?>
	<div class="esx-card esx-card-empty">
		<div class="esx-empty">
			<h2 class="esx-empty-title"><?php echo esc_html__( 'No forms yet', 'emailsendx-sync' ); ?></h2>
			<p class="esx-empty-sub"><?php echo esc_html__( 'Create your first form in the EmailSendX dashboard — it’ll show up here.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( $dashboard ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html__( 'Open dashboard ↗', 'emailsendx-sync' ); ?>
			</a>
		</div>
	</div>
<?php else : ?>
	<div class="esx-card">
		<h3 class="esx-card-title"><?php echo esc_html__( 'Your forms', 'emailsendx-sync' ); ?></h3>
		<table class="esx-int-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Form', 'emailsendx-sync' ); ?></th>
					<th><?php echo esc_html__( 'Shortcode', 'emailsendx-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $forms as $f ) : ?>
					<?php
					$fid   = isset( $f['id'] ) ? (string) $f['id'] : '';
					$fname = isset( $f['name'] ) && '' !== $f['name'] ? (string) $f['name'] : $fid;
					if ( '' === $fid ) {
						continue;
					}
					?>
					<tr>
						<td><?php echo esc_html( $fname ); ?></td>
						<td><code class="esx-code esx-code-inline">[emailsendx_form id="<?php echo esc_attr( $fid ); ?>"]</code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
