<?php
/**
 * Newsletter tab view.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=newsletter`.
 * Explains the [emailsendx_newsletter] shortcode / builder element and
 * lists the workspace's contact lists with a ready-to-paste snippet.
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
			<p class="esx-empty-sub"><?php echo esc_html__( 'Add your API key on the Settings tab to load your contact lists here.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( EmailSendX_Admin::get_admin_url( 'settings' ) ); ?>">
				<?php echo esc_html__( 'Go to Settings', 'emailsendx-sync' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
endif;

// ── Load the workspace's lists (best-effort). ──
$lists      = array();
$load_error = '';
if ( class_exists( 'EmailSendX_API' ) && function_exists( 'emailsendx_sync_fetch_all_lists' ) ) {
	$fetch = emailsendx_sync_fetch_all_lists( EmailSendX_API::instance() );
	if ( isset( $fetch['error'] ) && $fetch['error'] instanceof WP_Error ) {
		$load_error = $fetch['error']->get_error_message();
	} elseif ( isset( $fetch['lists'] ) && is_array( $fetch['lists'] ) ) {
		$lists = $fetch['lists'];
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
		<h2 class="esx-card-title"><?php echo esc_html__( 'Newsletter signup', 'emailsendx-sync' ); ?></h2>
		<p class="esx-card-sub">
			<?php echo esc_html__( 'A quick subscribe box that adds people straight to a contact list (single opt-in). For confirmed opt-in, use a Form instead.', 'emailsendx-sync' ); ?>
		</p>
	</div>
	<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( $dashboard ); ?>" target="_blank" rel="noopener">
		<?php echo esc_html__( 'Manage lists ↗', 'emailsendx-sync' ); ?>
	</a>
</div>

<div class="esx-card">
	<h3 class="esx-card-title"><?php echo esc_html__( 'Two ways to add a signup box', 'emailsendx-sync' ); ?></h3>
	<p class="esx-card-sub"><?php echo esc_html__( 'With a page builder: drop in the “EmailSendX Newsletter” element and pick a list. Anywhere else: paste the shortcode.', 'emailsendx-sync' ); ?></p>
	<p><code class="esx-code">[emailsendx_newsletter list="YOUR_LIST_ID"]</code></p>
</div>

<?php if ( '' !== $load_error ) : ?>
	<div class="esx-card esx-card-quiet">
		<p class="esx-card-sub" style="margin:0;">
			<?php echo esc_html__( 'Couldn’t load your lists right now. You can still paste the shortcode above with a list ID from your dashboard.', 'emailsendx-sync' ); ?>
		</p>
	</div>
<?php elseif ( empty( $lists ) ) : ?>
	<div class="esx-card esx-card-empty">
		<div class="esx-empty">
			<h2 class="esx-empty-title"><?php echo esc_html__( 'No contact lists yet', 'emailsendx-sync' ); ?></h2>
			<p class="esx-empty-sub"><?php echo esc_html__( 'Create a list in the EmailSendX dashboard — it’ll show up here.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( $dashboard ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html__( 'Open dashboard ↗', 'emailsendx-sync' ); ?>
			</a>
		</div>
	</div>
<?php else : ?>
	<div class="esx-card">
		<h3 class="esx-card-title"><?php echo esc_html__( 'Your lists', 'emailsendx-sync' ); ?></h3>
		<table class="esx-int-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'List', 'emailsendx-sync' ); ?></th>
					<th><?php echo esc_html__( 'Shortcode', 'emailsendx-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lists as $l ) : ?>
					<?php
					$lid   = isset( $l['id'] ) ? (string) $l['id'] : '';
					$lname = isset( $l['name'] ) && '' !== $l['name'] ? (string) $l['name'] : $lid;
					if ( '' === $lid ) {
						continue;
					}
					?>
					<tr>
						<td><?php echo esc_html( $lname ); ?></td>
						<td><code class="esx-code esx-code-inline">[emailsendx_newsletter list="<?php echo esc_attr( $lid ); ?>"]</code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
