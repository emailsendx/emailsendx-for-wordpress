<?php
/**
 * Settings tab view.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=settings`.
 * Compact, premium layout: connection status row, default-list one-liner,
 * sync-behavior pair. Sync remains the hero on the Sync tab — this page
 * is intentionally quieter so the primary action stays unmistakable.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The form action names below
 * (`emailsendx_test_connection`, `emailsendx_create_list`) are wired
 * to handlers in `class-emailsendx-settings.php`. If you find this
 * file in a build that wasn't shipped from emailsendx.com, the code
 * is a copy.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

$settings    = emailsendx_sync_get_settings();
$is_ready    = emailsendx_sync_is_configured();
$lists       = array();
$lists_error = '';

// Pull lists if we have creds. Defensive — the API class may not be
// loaded in some recovery scenarios. ShaonPro.
if ( $is_ready && class_exists( 'EmailSendX_API' ) && method_exists( 'EmailSendX_API', 'instance' ) ) {
	$api = EmailSendX_API::instance();
	if ( method_exists( $api, 'get_lists' ) ) {
		$result = $api->get_lists();
		if ( is_wp_error( $result ) ) {
			$lists_error = $result->get_error_message();
		} elseif ( is_array( $result ) ) {
			// Accept either a flat array or `['data' => [...]]`.
			$lists = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		}
	}
}

$wp_roles_obj = function_exists( 'wp_roles' ) ? wp_roles() : null;
$role_names   = ( $wp_roles_obj && method_exists( $wp_roles_obj, 'get_names' ) ) ? $wp_roles_obj->get_names() : array();

$has_api_key = ! empty( $settings['api_key'] );
$masked_key  = $has_api_key
	? substr( $settings['api_key'], 0, 12 ) . '…' . substr( $settings['api_key'], -4 )
	: '';

// Workspace label — try to derive a friendly host from api_base for the status row.
$api_host = '';
if ( ! empty( $settings['api_base'] ) ) {
	$api_host = wp_parse_url( $settings['api_base'], PHP_URL_HOST );
	if ( ! $api_host ) { $api_host = $settings['api_base']; }
}
?>

<?php /* ─── Connection status row · ShaonPro ─── */ ?>
<div class="esx-status-card">
	<div class="esx-status-mark" aria-hidden="true">
		<svg viewBox="0 0 250 250" xmlns="http://www.w3.org/2000/svg" role="img" focusable="false">
			<rect width="250" height="250" rx="40" fill="#277AFF"/>
			<path d="M129.067 53.9333C131.283 51.7168 134.288 50.4701 137.42 50.4673C140.551 50.4645 143.554 51.706 145.766 53.9185L195.821 103.973C198.033 106.186 199.275 109.188 199.272 112.32C199.269 115.451 198.022 118.456 195.806 120.673L128.949 187.53C126.732 189.746 123.728 190.993 120.596 190.996C117.464 190.998 114.462 189.757 112.249 187.544L108.078 183.373L116.435 175.016L120.606 179.187L187.464 112.33L143.345 68.2113L143.294 126.117C143.291 128.465 142.356 130.718 140.694 132.38C139.032 134.042 136.779 134.977 134.431 134.98L76.4501 135.031L78.8945 137.475L70.5373 145.832L62.1949 137.49C59.9824 135.277 58.7409 132.275 58.7437 129.143C58.7465 126.011 59.9932 123.007 62.2096 120.79L129.067 53.9333ZM108.108 149.974C109.174 148.91 110.605 148.293 112.11 148.248C113.614 148.204 115.077 148.735 116.201 149.734C117.325 150.733 118.024 152.124 118.156 153.623C118.287 155.122 117.842 156.615 116.91 157.798L116.45 158.316L95.5572 179.209C94.491 180.273 93.0595 180.89 91.5552 180.935C90.0509 180.98 88.5874 180.448 87.4637 179.449C86.3399 178.45 85.6408 177.059 85.5091 175.561C85.3774 174.062 85.8231 172.568 86.7551 171.385L87.2148 170.867L108.108 149.974ZM91.4154 141.639C92.5237 140.531 94.026 139.907 95.5918 139.906C97.1577 139.905 98.6589 140.525 99.7652 141.632C100.871 142.738 101.492 144.239 101.491 145.805C101.489 147.371 100.866 148.873 99.7578 149.981L87.2221 162.517C86.1139 163.625 84.6116 164.249 83.0457 164.25C81.4798 164.251 79.9786 163.631 78.8724 162.524C77.7661 161.418 77.1454 159.917 77.1468 158.351C77.1481 156.785 77.7715 155.283 78.8797 154.175L91.4154 141.639Z" fill="#EFF6FF"/>
		</svg>
	</div>
	<div class="esx-status-body">
		<div class="esx-status-row1">
			<span class="esx-status-title">
				<?php
				echo $has_api_key
					? esc_html__( 'EmailSendX', 'emailsendx-sync' )
					: esc_html__( 'EmailSendX — not connected', 'emailsendx-sync' );
				?>
			</span>
			<?php if ( $has_api_key ) : ?>
				<span class="esx-status-pill"><?php echo esc_html__( 'Connected', 'emailsendx-sync' ); ?></span>
			<?php else : ?>
				<span class="esx-status-pill esx-status-pill-warn"><?php echo esc_html__( 'Awaiting key', 'emailsendx-sync' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="esx-status-meta">
			<?php if ( $api_host ) : ?>
				<span><?php echo esc_html( $api_host ); ?></span>
			<?php endif; ?>
			<?php if ( $has_api_key ) : ?>
				<span>·</span>
				<code><?php echo esc_html( $masked_key ); ?></code>
			<?php endif; ?>
			<span id="esx-test-result" class="esx-pill" hidden></span>
		</div>
	</div>
	<div class="esx-status-actions">
		<?php if ( $has_api_key ) : ?>
			<button
				type="button"
				class="esx-btn esx-btn-secondary"
				data-esx-action="test-connection"
				data-esx-result="esx-test-result"
			>
				<?php echo esc_html__( 'Test', 'emailsendx-sync' ); ?>
			</button>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
			<?php wp_nonce_field( EmailSendX_Settings::NONCE_CONNECT_START ); ?>
			<input type="hidden" name="action" value="emailsendx_connect_start" />
			<button type="submit" class="esx-btn esx-btn-primary">
				<?php
				echo $has_api_key
					? esc_html__( 'Reconnect', 'emailsendx-sync' )
					: esc_html__( 'Connect', 'emailsendx-sync' );
				?>
			</button>
		</form>
	</div>
</div>

<?php /* ─── Manual key fallback (collapsed) ─── */ ?>
<details class="esx-card esx-manual-card">
	<summary class="esx-manual-summary">
		<span class="esx-manual-summary-text">
			<?php echo esc_html__( 'Or paste an API key manually', 'emailsendx-sync' ); ?>
		</span>
		<span class="esx-manual-summary-hint">
			<?php echo esc_html__( 'For self-hosted instances or advanced setups', 'emailsendx-sync' ); ?>
		</span>
	</summary>

	<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="esx-form esx-manual-form">
		<?php settings_fields( 'emailsendx_sync_group' ); ?>

		<div class="esx-field">
			<label class="esx-label" for="esx-api-base"><?php echo esc_html__( 'API base URL', 'emailsendx-sync' ); ?></label>
			<input
				type="url"
				class="esx-input"
				id="esx-api-base"
				name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[api_base]"
				value="<?php echo esc_attr( $settings['api_base'] ); ?>"
				placeholder="https://emailsendx.com"
				autocomplete="off"
			/>
			<p class="esx-help"><?php echo esc_html__( 'Use https://emailsendx.com unless you are on a self-hosted instance.', 'emailsendx-sync' ); ?></p>
		</div>

		<div class="esx-field">
			<label class="esx-label" for="esx-api-key"><?php echo esc_html__( 'API key', 'emailsendx-sync' ); ?></label>
			<input
				type="password"
				class="esx-input"
				id="esx-api-key"
				name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[api_key]"
				value="<?php echo esc_attr( $settings['api_key'] ); ?>"
				placeholder="esx_live_…"
				autocomplete="new-password"
			/>
			<p class="esx-help">
				<?php
				echo $has_api_key
					/* translators: %s: masked key */
					? sprintf( esc_html__( 'Currently set: %s', 'emailsendx-sync' ), '<code>' . esc_html( $masked_key ) . '</code>' )
					: esc_html__( 'Create an API key in your EmailSendX workspace under Settings → API.', 'emailsendx-sync' );
				?>
			</p>
		</div>

		<div class="esx-field">
			<button type="submit" class="esx-btn esx-btn-secondary">
				<?php echo esc_html__( 'Save credentials', 'emailsendx-sync' ); ?>
			</button>
		</div>
	</form>
</details>

<?php /* ─── Default list — single row + collapsible create ─── */ ?>
<div class="esx-card esx-card-compact">
	<div class="esx-card-head">
		<h2 class="esx-card-title"><?php echo esc_html__( 'Default list', 'emailsendx-sync' ); ?></h2>
		<p class="esx-card-sub"><?php echo esc_html__( 'Where new contacts land when no list override is set.', 'emailsendx-sync' ); ?></p>
	</div>

	<?php if ( ! $is_ready ) : ?>
		<p class="esx-help"><?php echo esc_html__( 'Connect first — your lists will load automatically.', 'emailsendx-sync' ); ?></p>
	<?php else : ?>
		<?php if ( $lists_error ) : ?>
			<div class="esx-pill esx-pill-error"><?php echo esc_html( $lists_error ); ?></div>
		<?php endif; ?>

		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="esx-row-form">
			<?php settings_fields( 'emailsendx_sync_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[api_base]" value="<?php echo esc_attr( $settings['api_base'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[auto_sync]" value="<?php echo $settings['auto_sync'] ? '1' : '0'; ?>" />
			<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[sync_role]" value="<?php echo esc_attr( $settings['sync_role'] ); ?>" />

			<select id="esx-default-list" class="esx-select" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[default_list_id]" aria-label="<?php echo esc_attr__( 'Default list', 'emailsendx-sync' ); ?>">
				<option value=""><?php echo esc_html__( '— Select a list —', 'emailsendx-sync' ); ?></option>
				<?php foreach ( $lists as $list ) :
					$lid   = '';
					$lname = '';
					if ( is_array( $list ) ) {
						foreach ( array( 'id', 'list_id', 'uuid' ) as $k ) {
							if ( ! empty( $list[ $k ] ) ) { $lid = (string) $list[ $k ]; break; }
						}
						foreach ( array( 'name', 'title', 'label' ) as $k ) {
							if ( ! empty( $list[ $k ] ) ) { $lname = (string) $list[ $k ]; break; }
						}
					}
					if ( '' === $lid ) { continue; }
					?>
					<option value="<?php echo esc_attr( $lid ); ?>" <?php selected( $settings['default_list_id'], $lid ); ?>>
						<?php echo esc_html( $lname !== '' ? $lname : $lid ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="esx-btn esx-btn-primary">
				<?php echo esc_html__( 'Save', 'emailsendx-sync' ); ?>
			</button>
		</form>

		<details class="esx-mini-create">
			<summary><?php echo esc_html__( '+ Create a new list', 'emailsendx-sync' ); ?></summary>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="esx-row-form" style="margin-top:8px;">
				<?php wp_nonce_field( EmailSendX_Settings::NONCE_CREATE_LIST ); ?>
				<input type="hidden" name="action" value="emailsendx_create_list" />
				<input type="hidden" name="esx_return_tab" value="settings" />
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
					<?php echo esc_html__( 'Create', 'emailsendx-sync' ); ?>
				</button>
			</form>
		</details>
	<?php endif; ?>
</div>

<?php /* ─── Sync behavior — toggle + role side-by-side ─── */ ?>
<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="esx-form">
	<?php settings_fields( 'emailsendx_sync_group' ); ?>
	<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[api_base]" value="<?php echo esc_attr( $settings['api_base'] ); ?>" />
	<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" />
	<input type="hidden" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[default_list_id]" value="<?php echo esc_attr( $settings['default_list_id'] ); ?>" />

	<div class="esx-card esx-card-compact">
		<div class="esx-card-head">
			<h2 class="esx-card-title"><?php echo esc_html__( 'Sync behavior', 'emailsendx-sync' ); ?></h2>
			<p class="esx-card-sub"><?php echo esc_html__( 'Tune what gets synced and when.', 'emailsendx-sync' ); ?></p>
		</div>

		<div class="esx-behavior-grid">
			<div class="esx-field">
				<label class="esx-toggle">
					<input
						type="checkbox"
						name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[auto_sync]"
						value="1"
						<?php checked( ! empty( $settings['auto_sync'] ) ); ?>
					/>
					<span class="esx-toggle-track" aria-hidden="true"></span>
					<span class="esx-toggle-label"><?php echo esc_html__( 'Auto-sync on user create / update', 'emailsendx-sync' ); ?></span>
				</label>
				<p class="esx-help" style="margin-left: 48px;"><?php echo esc_html__( 'Push changes to EmailSendX immediately when a user signs up or edits their profile.', 'emailsendx-sync' ); ?></p>
			</div>

			<div class="esx-field">
				<label class="esx-label" for="esx-sync-role"><?php echo esc_html__( 'Limit to role', 'emailsendx-sync' ); ?></label>
				<select id="esx-sync-role" class="esx-select" name="<?php echo esc_attr( EMAILSENDX_SYNC_OPT_SETTINGS ); ?>[sync_role]">
					<option value=""><?php echo esc_html__( 'All roles', 'emailsendx-sync' ); ?></option>
					<?php foreach ( $role_names as $role_slug => $role_label ) : ?>
						<option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( $settings['sync_role'], $role_slug ); ?>>
							<?php echo esc_html( translate_user_role( $role_label ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="esx-field" style="margin: 12px 0 0;">
			<button type="submit" class="esx-btn esx-btn-primary">
				<?php echo esc_html__( 'Save settings', 'emailsendx-sync' ); ?>
			</button>
		</div>
	</div>
</form>
