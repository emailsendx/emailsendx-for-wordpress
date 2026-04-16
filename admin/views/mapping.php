<?php
/**
 * Admin view: Field mapping tab.
 *
 * Renders the source picker, the two-column mapping table, the
 * "+ Add row" affordance, and the "Create custom field" modal. The
 * form posts to `admin-post.php?action=emailsendx_save_mapping` —
 * the handler is wired up in EmailSendX_Admin (not in this view).
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. Markup classes match the
 * `assets/css/admin.css` premium stylesheet shipped alongside.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

// Source picker — `users` is the safe default; fall back to it if the
// user landed on the woocommerce tab without WC installed.
$esx_source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : 'users'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav.
if ( ! in_array( $esx_source, array( 'users', 'woocommerce' ), true ) ) {
	$esx_source = 'users';
}
$esx_woo_active = class_exists( 'WooCommerce' );
if ( 'woocommerce' === $esx_source && ! $esx_woo_active ) {
	$esx_source = 'users';
}

$esx_source_fields = EmailSendX_Mapper::get_source_fields( $esx_source );
$esx_targets       = EmailSendX_Mapper::get_target_fields();
$esx_mapping       = EmailSendX_Mapper::get_mapping( $esx_source );

$esx_base_url = admin_url( 'admin.php?page=emailsendx-sync&tab=mapping' );

/**
 * Render a target <select> populated with builtin + custom fields and
 * the "+ Create new custom field…" sentinel option. Kept as a closure
 * so we can also use it for the hidden "+ Add row" template. ShaonPro.
 *
 * @param string $name        HTML name attribute.
 * @param string $current     Currently-selected value (e.g. "email").
 * @param string $current_typ Currently-selected type ("builtin"/"custom"/"metadata").
 * @param array  $targets     Target field dictionary from the mapper.
 * @return void
 */
$esx_render_select = static function ( $name, $current, $current_typ, $targets ) {
	?>
	<select class="esx-select esx-target-select" name="<?php echo esc_attr( $name ); ?>" data-current-type="<?php echo esc_attr( $current_typ ); ?>">
		<option value=""><?php esc_html_e( '— Do not sync —', 'emailsendx-sync' ); ?></option>
		<optgroup label="<?php esc_attr_e( 'Built-in', 'emailsendx-sync' ); ?>">
			<?php foreach ( $targets['builtin'] as $key => $label ) : ?>
				<?php $val = 'builtin::' . $key; ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, ( 'builtin' === $current_typ ? 'builtin::' . $current : '' ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</optgroup>
		<optgroup label="<?php esc_attr_e( 'Custom fields', 'emailsendx-sync' ); ?>" class="esx-optgroup-custom">
			<?php foreach ( $targets['custom'] as $key => $label ) : ?>
				<?php $val = 'custom::' . $key; ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, ( 'custom' === $current_typ ? 'custom::' . $current : '' ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
			<?php if ( 'metadata' === $current_typ && '' !== $current ) : ?>
				<option value="<?php echo esc_attr( 'metadata::' . $current ); ?>" selected>
					<?php echo esc_html( $current ); ?> <?php esc_html_e( '(metadata)', 'emailsendx-sync' ); ?>
				</option>
			<?php endif; ?>
		</optgroup>
		<option value="__create__"><?php esc_html_e( '+ Create new custom field…', 'emailsendx-sync' ); ?></option>
	</select>
	<?php
};
?>

<div class="esx-wrap">

	<div class="esx-tabs" role="tablist">
		<a href="<?php echo esc_url( add_query_arg( 'source', 'users', $esx_base_url ) ); ?>"
			class="esx-tab <?php echo 'users' === $esx_source ? 'is-active' : ''; ?>">
			<?php esc_html_e( 'WordPress Users', 'emailsendx-sync' ); ?>
		</a>
		<?php if ( $esx_woo_active ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'source', 'woocommerce', $esx_base_url ) ); ?>"
				class="esx-tab <?php echo 'woocommerce' === $esx_source ? 'is-active' : ''; ?>">
				<?php esc_html_e( 'WooCommerce Customers', 'emailsendx-sync' ); ?>
			</a>
		<?php else : ?>
			<span class="esx-tab is-disabled" aria-disabled="true" title="<?php esc_attr_e( 'WooCommerce is not active', 'emailsendx-sync' ); ?>">
				<?php esc_html_e( 'WooCommerce Customers', 'emailsendx-sync' ); ?>
			</span>
		<?php endif; ?>
	</div>

	<div class="esx-grid-2">

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="esx-card esx-mapping-card">
			<input type="hidden" name="action" value="emailsendx_save_mapping" />
			<input type="hidden" name="source" value="<?php echo esc_attr( $esx_source ); ?>" />
			<?php wp_nonce_field( 'emailsendx_save_mapping', 'emailsendx_mapping_nonce' ); ?>

			<h2>
				<?php
				/* translators: %s: source label (e.g. "WordPress Users") */
				printf(
					esc_html__( 'Field mapping for %s', 'emailsendx-sync' ),
					'users' === $esx_source
						? esc_html__( 'WordPress Users', 'emailsendx-sync' )
						: esc_html__( 'WooCommerce Customers', 'emailsendx-sync' )
				);
				?>
			</h2>
			<p class="esx-help">
				<?php esc_html_e( 'Match each WordPress field to where it should land in EmailSendX. Unmapped fields are ignored.', 'emailsendx-sync' ); ?>
			</p>

			<table class="esx-table esx-mapping-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'WordPress field', 'emailsendx-sync' ); ?></th>
						<th><?php esc_html_e( 'EmailSendX target', 'emailsendx-sync' ); ?></th>
						<th class="esx-col-actions"></th>
					</tr>
				</thead>
				<tbody class="esx-mapping-rows">
					<?php
					if ( empty( $esx_source_fields ) ) :
						?>
						<tr><td colspan="3" class="esx-empty-state-row">
							<?php esc_html_e( 'No source fields detected.', 'emailsendx-sync' ); ?>
						</td></tr>
						<?php
					else :
						foreach ( $esx_source_fields as $sf_key => $sf_label ) :
							$rule    = isset( $esx_mapping[ $sf_key ] ) ? $esx_mapping[ $sf_key ] : array( 'target' => '', 'type' => '' );
							$target  = isset( $rule['target'] ) ? (string) $rule['target'] : '';
							$type    = isset( $rule['type'] )   ? (string) $rule['type']   : '';
							$row_name = sprintf( 'mapping[%s][%s]', $esx_source, $sf_key );
							?>
							<tr class="esx-mapping-row">
								<td class="esx-mapping-source">
									<strong><?php echo esc_html( $sf_label ); ?></strong>
									<code class="esx-source-key"><?php echo esc_html( $sf_key ); ?></code>
								</td>
								<td class="esx-mapping-target">
									<?php $esx_render_select( $row_name, $target, $type, $esx_targets ); ?>
								</td>
								<td class="esx-col-actions"></td>
							</tr>
							<?php
						endforeach;
					endif;

					// Already-saved custom meta:* rows that aren't part of get_field_options() output.
					if ( ! empty( $esx_mapping ) ) {
						foreach ( $esx_mapping as $sf_key => $rule ) {
							if ( isset( $esx_source_fields[ $sf_key ] ) ) {
								continue;
							}
							$target   = isset( $rule['target'] ) ? (string) $rule['target'] : '';
							$type     = isset( $rule['type'] )   ? (string) $rule['type']   : '';
							$row_name = sprintf( 'mapping[%s][%s]', $esx_source, $sf_key );
							?>
							<tr class="esx-mapping-row esx-mapping-row-extra">
								<td class="esx-mapping-source">
									<input type="text" class="esx-input esx-extra-source"
										name="<?php echo esc_attr( sprintf( 'mapping_keys[%s][]', $esx_source ) ); ?>"
										value="<?php echo esc_attr( $sf_key ); ?>"
										data-row-name-template="<?php echo esc_attr( sprintf( 'mapping[%s][__KEY__]', $esx_source ) ); ?>" />
								</td>
								<td class="esx-mapping-target">
									<?php $esx_render_select( $row_name, $target, $type, $esx_targets ); ?>
								</td>
								<td class="esx-col-actions">
									<button type="button" class="esx-btn esx-btn-ghost esx-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'emailsendx-sync' ); ?>">&times;</button>
								</td>
							</tr>
							<?php
						}
					}
					?>
				</tbody>
			</table>

			<div class="esx-mapping-toolbar">
				<button type="button" class="esx-btn esx-btn-secondary" data-esx-action="add-row">
					+ <?php esc_html_e( 'Add row', 'emailsendx-sync' ); ?>
				</button>
				<button type="submit" class="esx-btn esx-btn-primary">
					<?php esc_html_e( 'Save mapping', 'emailsendx-sync' ); ?>
				</button>
			</div>

			<?php // Hidden template row consumed by admin.js when the user clicks "+ Add row". ShaonPro. ?>
			<template id="esx-mapping-row-template">
				<tr class="esx-mapping-row esx-mapping-row-extra">
					<td class="esx-mapping-source">
						<input type="text" class="esx-input esx-extra-source"
							placeholder="meta:phone"
							data-row-name-template="<?php echo esc_attr( sprintf( 'mapping[%s][__KEY__]', $esx_source ) ); ?>" />
					</td>
					<td class="esx-mapping-target">
						<?php $esx_render_select( '__placeholder__', '', '', $esx_targets ); ?>
					</td>
					<td class="esx-col-actions">
						<button type="button" class="esx-btn esx-btn-ghost esx-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'emailsendx-sync' ); ?>">&times;</button>
					</td>
				</tr>
			</template>
		</form>

		<aside class="esx-card esx-side-card">
			<h2><?php esc_html_e( 'Quick guide', 'emailsendx-sync' ); ?></h2>
			<p class="esx-help">
				<?php esc_html_e( 'Use these merge tags inside any EmailSendX email template.', 'emailsendx-sync' ); ?>
			</p>
			<ul class="esx-merge-tags">
				<li>
					<code>{{contact.firstName}}</code>
					<span><?php esc_html_e( 'First name (built-in)', 'emailsendx-sync' ); ?></span>
				</li>
				<li>
					<code>{{contact.lastName}}</code>
					<span><?php esc_html_e( 'Last name (built-in)', 'emailsendx-sync' ); ?></span>
				</li>
				<li>
					<code>{{contact.email}}</code>
					<span><?php esc_html_e( 'Email address', 'emailsendx-sync' ); ?></span>
				</li>
				<li>
					<code>{{contact.custom.&lt;key&gt;}}</code>
					<span><?php esc_html_e( 'Any custom field by key', 'emailsendx-sync' ); ?></span>
				</li>
			</ul>
			<p class="esx-help">
				<?php esc_html_e( 'Tip: pick "+ Create new custom field…" in any dropdown to add a field on the fly.', 'emailsendx-sync' ); ?>
			</p>
		</aside>

	</div>
</div>

<?php // ─── Create custom field modal — opened by admin.js. ShaonPro. ─── ?>
<div class="esx-modal-backdrop" id="esx-create-field-modal" hidden>
	<div class="esx-modal" role="dialog" aria-modal="true" aria-labelledby="esx-create-field-title">
		<div class="esx-modal-header">
			<h3 id="esx-create-field-title"><?php esc_html_e( 'Create custom field', 'emailsendx-sync' ); ?></h3>
			<button type="button" class="esx-btn esx-btn-ghost" data-esx-action="close-modal" aria-label="<?php esc_attr_e( 'Close', 'emailsendx-sync' ); ?>">&times;</button>
		</div>
		<div class="esx-modal-body">
			<label class="esx-field">
				<span><?php esc_html_e( 'Key', 'emailsendx-sync' ); ?></span>
				<input type="text" class="esx-input" id="esx-cf-key" placeholder="company_size" />
				<small class="esx-help"><?php esc_html_e( 'Lowercase letters, numbers, underscore. Max 50 chars.', 'emailsendx-sync' ); ?></small>
			</label>
			<label class="esx-field">
				<span><?php esc_html_e( 'Label', 'emailsendx-sync' ); ?></span>
				<input type="text" class="esx-input" id="esx-cf-label" placeholder="Company size" />
			</label>
			<label class="esx-field">
				<span><?php esc_html_e( 'Type', 'emailsendx-sync' ); ?></span>
				<select class="esx-select" id="esx-cf-type">
					<option value="text"><?php esc_html_e( 'Text', 'emailsendx-sync' ); ?></option>
					<option value="number"><?php esc_html_e( 'Number', 'emailsendx-sync' ); ?></option>
					<option value="date"><?php esc_html_e( 'Date', 'emailsendx-sync' ); ?></option>
					<option value="boolean"><?php esc_html_e( 'Boolean', 'emailsendx-sync' ); ?></option>
				</select>
			</label>
			<div class="esx-modal-error esx-pill esx-pill-error" hidden></div>
		</div>
		<div class="esx-modal-footer">
			<button type="button" class="esx-btn esx-btn-secondary" data-esx-action="close-modal">
				<?php esc_html_e( 'Cancel', 'emailsendx-sync' ); ?>
			</button>
			<button type="button" class="esx-btn esx-btn-primary" data-esx-action="submit-create-field">
				<?php esc_html_e( 'Create field', 'emailsendx-sync' ); ?>
			</button>
		</div>
	</div>
</div>
