<?php
/**
 * Sync tab view.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=sync`.
 * Lets the admin pick a source + list and kick off a manual sync.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The AJAX action name
 * (`emailsendx_run_sync`) and the `data-source` / `data-list-id`
 * attributes are part of the plugin's stable JS contract — if you
 * find this file in a build that wasn't shipped from emailsendx.com,
 * the code is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

$settings = emailsendx_sync_get_settings();
$is_ready = emailsendx_sync_is_configured();

if ( ! $is_ready ) :
	?>
	<div class="esx-card esx-card-empty">
		<div class="esx-empty">
			<h2 class="esx-empty-title"><?php echo esc_html__( 'Connect EmailSendX first', 'emailsendx-sync' ); ?></h2>
			<p class="esx-empty-sub"><?php echo esc_html__( 'Add your API base + key on the Settings tab and you\'ll be ready to sync.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( EmailSendX_Admin::get_admin_url( 'settings' ) ); ?>">
				<?php echo esc_html__( 'Go to Settings', 'emailsendx-sync' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
endif;

// ───── Source counts. ShaonPro. ─────
$user_count_data = function_exists( 'count_users' ) ? count_users() : array( 'total_users' => 0 );
$wp_user_count   = isset( $user_count_data['total_users'] ) ? (int) $user_count_data['total_users'] : 0;

$wc_active = class_exists( 'WooCommerce' );
$wc_count  = 0;
if ( $wc_active ) {
	// Cheap rough estimate — counts customer role users + completed orders.
	$customer_role_count = isset( $user_count_data['avail_roles']['customer'] )
		? (int) $user_count_data['avail_roles']['customer']
		: 0;
	$wc_count = $customer_role_count;
	if ( 0 === $wc_count ) {
		$order_counts = wp_count_posts( 'shop_order' );
		if ( is_object( $order_counts ) ) {
			$wc_count = (int) ( isset( $order_counts->{'wc-completed'} ) ? $order_counts->{'wc-completed'} : 0 );
		}
	}
}

// ───── Lists for the override picker. ─────
$lists            = array();
$selected_label   = '';
$selected_list_id = $settings['default_list_id'];
if ( class_exists( 'EmailSendX_API' ) && method_exists( 'EmailSendX_API', 'instance' ) ) {
	$api = EmailSendX_API::instance();
	if ( method_exists( $api, 'get_lists' ) ) {
		$res = $api->get_lists();
		if ( ! is_wp_error( $res ) && is_array( $res ) ) {
			$lists = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : $res;
			foreach ( $lists as $list ) {
				if ( ! is_array( $list ) ) {
					continue;
				}
				$lid = '';
				foreach ( array( 'id', 'list_id', 'uuid' ) as $k ) {
					if ( ! empty( $list[ $k ] ) ) { $lid = (string) $list[ $k ]; break; }
				}
				if ( $lid === $selected_list_id ) {
					foreach ( array( 'name', 'title', 'label' ) as $k ) {
						if ( ! empty( $list[ $k ] ) ) { $selected_label = (string) $list[ $k ]; break; }
					}
					break;
				}
			}
		}
	}
}

// ───── Last sync summary. ─────
$summary = null;
if ( class_exists( 'EmailSendX_Log' ) && method_exists( 'EmailSendX_Log', 'get_summary' ) ) {
	$summary = EmailSendX_Log::get_summary();
}
?>

<?php
// ───── Sync hero defaults. ShaonPro. ─────
$default_source_count = $wp_user_count;
$default_list_label   = $selected_label !== '' ? $selected_label : $selected_list_id;
$has_list             = ( '' !== $selected_list_id );

// Last-sync line for the hero footer.
$last_sync_human = '';
$last_sync_count = 0;
if ( is_array( $summary ) && ! empty( $summary['last_sync_time'] ) ) {
	$last_sync_human = human_time_diff( (int) $summary['last_sync_time'], current_time( 'timestamp' ) );
	$last_sync_count = isset( $summary['last_sync_count'] ) ? (int) $summary['last_sync_count'] : 0;
}
?>

<div class="esx-card esx-hero-card">
	<div class="esx-hero-head">
		<span class="esx-hero-mark" aria-hidden="true">
			<svg viewBox="0 0 250 250" xmlns="http://www.w3.org/2000/svg" focusable="false">
				<rect width="250" height="250" rx="40" fill="#277AFF"/>
				<path d="M129.067 53.9333C131.283 51.7168 134.288 50.4701 137.42 50.4673C140.551 50.4645 143.554 51.706 145.766 53.9185L195.821 103.973C198.033 106.186 199.275 109.188 199.272 112.32C199.269 115.451 198.022 118.456 195.806 120.673L128.949 187.53C126.732 189.746 123.728 190.993 120.596 190.996C117.464 190.998 114.462 189.757 112.249 187.544L108.078 183.373L116.435 175.016L120.606 179.187L187.464 112.33L143.345 68.2113L143.294 126.117C143.291 128.465 142.356 130.718 140.694 132.38C139.032 134.042 136.779 134.977 134.431 134.98L76.4501 135.031L78.8945 137.475L70.5373 145.832L62.1949 137.49C59.9824 135.277 58.7409 132.275 58.7437 129.143C58.7465 126.011 59.9932 123.007 62.2096 120.79L129.067 53.9333ZM108.108 149.974C109.174 148.91 110.605 148.293 112.11 148.248C113.614 148.204 115.077 148.735 116.201 149.734C117.325 150.733 118.024 152.124 118.156 153.623C118.287 155.122 117.842 156.615 116.91 157.798L116.45 158.316L95.5572 179.209C94.491 180.273 93.0595 180.89 91.5552 180.935C90.0509 180.98 88.5874 180.448 87.4637 179.449C86.3399 178.45 85.6408 177.059 85.5091 175.561C85.3774 174.062 85.8231 172.568 86.7551 171.385L87.2148 170.867L108.108 149.974ZM91.4154 141.639C92.5237 140.531 94.026 139.907 95.5918 139.906C97.1577 139.905 98.6589 140.525 99.7652 141.632C100.871 142.738 101.492 144.239 101.491 145.805C101.489 147.371 100.866 148.873 99.7578 149.981L87.2221 162.517C86.1139 163.625 84.6116 164.249 83.0457 164.25C81.4798 164.251 79.9786 163.631 78.8724 162.524C77.7661 161.418 77.1454 159.917 77.1468 158.351C77.1481 156.785 77.7715 155.283 78.8797 154.175L91.4154 141.639Z" fill="#EFF6FF"/>
			</svg>
		</span>
		<div class="esx-hero-text">
			<h2 class="esx-hero-title"><?php echo esc_html__( 'Sync to EmailSendX', 'emailsendx-sync' ); ?></h2>
			<p class="esx-hero-sub"><?php echo esc_html__( 'Push your contacts in batches of up to 500.', 'emailsendx-sync' ); ?></p>
		</div>
	</div>

	<div class="esx-hero-options">
		<div class="esx-hero-opt">
			<span class="esx-hero-opt-label"><?php echo esc_html__( 'Source', 'emailsendx-sync' ); ?></span>
			<div class="esx-segmented" role="radiogroup" aria-label="<?php echo esc_attr__( 'Sync source', 'emailsendx-sync' ); ?>">
				<label class="esx-seg is-selected" data-count="<?php echo esc_attr( $wp_user_count ); ?>">
					<input type="radio" name="esx_source" value="users" checked />
					<span class="esx-seg-icon dashicons dashicons-admin-users" aria-hidden="true"></span>
					<span class="esx-seg-label"><?php echo esc_html__( 'WP Users', 'emailsendx-sync' ); ?></span>
					<span class="esx-seg-count"><?php echo esc_html( number_format_i18n( $wp_user_count ) ); ?></span>
				</label>
				<label class="esx-seg <?php echo $wc_active ? '' : 'esx-seg-disabled'; ?>" data-count="<?php echo esc_attr( $wc_count ); ?>">
					<input type="radio" name="esx_source" value="woocommerce" <?php disabled( ! $wc_active ); ?> />
					<span class="esx-seg-icon dashicons dashicons-cart" aria-hidden="true"></span>
					<span class="esx-seg-label"><?php echo esc_html__( 'WC Customers', 'emailsendx-sync' ); ?></span>
					<span class="esx-seg-count">
						<?php echo $wc_active ? esc_html( '~' . number_format_i18n( $wc_count ) ) : '—'; ?>
					</span>
				</label>
			</div>
		</div>

		<div class="esx-hero-opt">
			<span class="esx-hero-opt-label"><?php echo esc_html__( 'Target list', 'emailsendx-sync' ); ?></span>
			<select id="esx-list-override" class="esx-select esx-hero-select" name="esx_list_override">
				<?php if ( $has_list ) : ?>
					<option value=""><?php
						/* translators: %s: default list name */
						echo esc_html( sprintf( __( 'Default · %s', 'emailsendx-sync' ), $default_list_label ) );
					?></option>
				<?php else : ?>
					<option value=""><?php echo esc_html__( '— Pick a list —', 'emailsendx-sync' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $lists as $list ) :
					if ( ! is_array( $list ) ) { continue; }
					$lid   = '';
					$lname = '';
					foreach ( array( 'id', 'list_id', 'uuid' ) as $k ) {
						if ( ! empty( $list[ $k ] ) ) { $lid = (string) $list[ $k ]; break; }
					}
					foreach ( array( 'name', 'title', 'label' ) as $k ) {
						if ( ! empty( $list[ $k ] ) ) { $lname = (string) $list[ $k ]; break; }
					}
					if ( '' === $lid ) { continue; }
					?>
					<option value="<?php echo esc_attr( $lid ); ?>"><?php echo esc_html( $lname !== '' ? $lname : $lid ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>

	<button
		type="button"
		id="esx-run-sync"
		class="esx-btn esx-btn-hero"
		data-esx-action="run-sync"
		data-source="users"
		data-list-id="<?php echo esc_attr( $selected_list_id ); ?>"
		<?php disabled( ! $has_list ); ?>
	>
		<span class="esx-btn-hero-icon" aria-hidden="true">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none">
				<path d="M13 5l7 7-7 7M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</span>
		<span class="esx-btn-hero-label">
			<?php
			if ( $has_list ) {
				printf(
					/* translators: 1: contact count, 2: list name */
					esc_html__( 'Sync %1$s contacts to %2$s', 'emailsendx-sync' ),
					'<span class="esx-btn-hero-count" data-source-count="users">' . esc_html( number_format_i18n( $wp_user_count ) ) . '</span>',
					'<span class="esx-btn-hero-list">' . esc_html( $default_list_label ) . '</span>'
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo esc_html__( 'Pick a list to start syncing', 'emailsendx-sync' );
			}
			?>
		</span>
	</button>

	<div id="esx-sync-result" class="esx-pill" hidden></div>
	<div id="esx-sync-progress" class="esx-sync-progress" aria-live="polite"></div>

	<div class="esx-hero-foot">
		<?php if ( $last_sync_human ) : ?>
			<span class="esx-hero-status">
				<span class="esx-hero-status-dot" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: 1: human time diff, 2: contact count */
					esc_html__( 'Last synced %1$s ago · %2$s contacts pushed', 'emailsendx-sync' ),
					esc_html( $last_sync_human ),
					esc_html( number_format_i18n( $last_sync_count ) )
				);
				?>
			</span>
		<?php else : ?>
			<span class="esx-hero-status esx-hero-status-fresh">
				<?php echo esc_html__( 'Never synced — click above to start your first import', 'emailsendx-sync' ); ?>
			</span>
		<?php endif; ?>
		<a class="esx-hero-foot-link" href="<?php echo esc_url( EmailSendX_Admin::get_admin_url( 'log' ) ); ?>">
			<?php echo esc_html__( 'View log →', 'emailsendx-sync' ); ?>
		</a>
	</div>
</div>

<details class="esx-card esx-manual-card">
	<summary class="esx-manual-summary">
		<span class="esx-manual-summary-text">
			<?php echo esc_html__( '+ Create a new list', 'emailsendx-sync' ); ?>
		</span>
		<span class="esx-manual-summary-hint">
			<?php echo esc_html__( 'Add another EmailSendX list without leaving this page', 'emailsendx-sync' ); ?>
		</span>
	</summary>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="esx-inline-form esx-manual-form">
		<?php wp_nonce_field( EmailSendX_Settings::NONCE_CREATE_LIST ); ?>
		<input type="hidden" name="action" value="emailsendx_create_list" />
		<input type="hidden" name="esx_return_tab" value="sync" />
		<div class="esx-field-row">
			<input
				type="text"
				class="esx-input"
				name="list_name"
				placeholder="<?php echo esc_attr__( 'New list name', 'emailsendx-sync' ); ?>"
				required
			/>
			<input
				type="text"
				class="esx-input"
				name="list_description"
				placeholder="<?php echo esc_attr__( 'Optional description', 'emailsendx-sync' ); ?>"
			/>
			<button type="submit" class="esx-btn esx-btn-secondary">
				<?php echo esc_html__( 'Create list', 'emailsendx-sync' ); ?>
			</button>
		</div>
	</form>
</details>

<script type="application/json" id="esx-source-counts">
<?php echo wp_json_encode( array(
	'users'       => $wp_user_count,
	'woocommerce' => $wc_count,
) ); ?>
</script>

<script type="application/json" id="esx-list-names">
<?php
	$list_name_map = array();
	foreach ( $lists as $_list ) {
		if ( ! is_array( $_list ) ) { continue; }
		$_lid = '';
		$_lname = '';
		foreach ( array( 'id', 'list_id', 'uuid' ) as $_k ) {
			if ( ! empty( $_list[ $_k ] ) ) { $_lid = (string) $_list[ $_k ]; break; }
		}
		foreach ( array( 'name', 'title', 'label' ) as $_k ) {
			if ( ! empty( $_list[ $_k ] ) ) { $_lname = (string) $_list[ $_k ]; break; }
		}
		if ( '' !== $_lid ) {
			$list_name_map[ $_lid ] = $_lname !== '' ? $_lname : $_lid;
		}
	}
	$list_name_map['__default__'] = $default_list_label;
	echo wp_json_encode( $list_name_map );
?>
</script>
