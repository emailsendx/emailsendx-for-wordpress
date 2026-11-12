<?php
/**
 * Carts tab view — abandoned cart recovery for WooCommerce.
 *
 * Rendered by `EmailSendX_Admin::render_page()` when `?tab=carts`. One
 * place for the whole feature: the setup checklist, the results, every
 * saved cart with its details, and the settings.
 *
 * `?cart=<id>` shows a single cart instead of the list.
 *
 * ─── ShaonPro signature ──────────────────────────────────────────────
 * Built by ShaonPro for EmailSendX. The admin-post action names below
 * (`emailsendx_cart_test`, `emailsendx_cart_delete`) are wired to
 * handlers in `class-emailsendx-cart.php`.
 *
 * @package EmailSendX_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access. ShaonPro.
}

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'EmailSendX_Cart' ) ) {
	echo '<div class="esx-card"><p>' . esc_html__( 'Abandoned cart recovery needs WooCommerce.', 'emailsendx-sync' ) . '</p></div>';
	return;
}

EmailSendX_Cart::ensure_table_current();

$settings      = emailsendx_sync_get_settings();
$is_ready      = emailsendx_sync_is_configured();
$esx_on        = ! empty( $settings['cart_enabled'] );
$esx_idle      = EmailSendX_Cart::sanitize_idle( isset( $settings['cart_idle_minutes'] ) ? $settings['cart_idle_minutes'] : 60 );
$esx_mode      = EmailSendX_Cart::sanitize_mode( isset( $settings['cart_consent_mode'] ) ? $settings['cart_consent_mode'] : 'notice' );
$esx_base_url  = EmailSendX_Admin::get_admin_url( 'carts' );
$esx_opt       = EMAILSENDX_SYNC_OPT_SETTINGS;
$esx_roles     = function_exists( 'emailsendx_sync_get_roles' ) ? (array) emailsendx_sync_get_roles() : array();
$esx_labels    = EmailSendX_Cart::status_labels();
$esx_date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

$esx_idle_labels = array(
	30  => __( '30 minutes', 'emailsendx-sync' ),
	60  => __( '1 hour', 'emailsendx-sync' ),
	120 => __( '2 hours', 'emailsendx-sync' ),
	240 => __( '4 hours', 'emailsendx-sync' ),
);
$esx_modes = array(
	'notice'    => array(
		__( 'Everyone who enters their email', 'emailsendx-sync' ),
		__( 'Shoppers see a short notice under the email field, with a “No thanks” link.', 'emailsendx-sync' ),
	),
	'checkbox'  => array(
		__( 'Only shoppers who tick a box', 'emailsendx-sync' ),
		__( 'Adds an unticked “Email me a reminder” box under the email field.', 'emailsendx-sync' ),
	),
	'customers' => array(
		__( 'Only logged-in customers', 'emailsendx-sync' ),
		__( 'Guests are never tracked or emailed.', 'emailsendx-sync' ),
	),
);

/**
 * The page's other settings, carried through untouched — the sanitizer
 * reads absent boxes as "off". Printed inside every form that saves the
 * cart settings.
 */
$esx_carry = function () use ( $settings, $esx_opt, $esx_roles ) {
	foreach ( array( 'api_base', 'api_key', 'default_list_id' ) as $esx_key ) {
		printf(
			'<input type="hidden" name="%1$s[%2$s]" value="%3$s" />',
			esc_attr( $esx_opt ),
			esc_attr( $esx_key ),
			esc_attr( isset( $settings[ $esx_key ] ) ? $settings[ $esx_key ] : '' )
		);
	}
	printf( '<input type="hidden" name="%1$s[auto_sync]" value="%2$s" />', esc_attr( $esx_opt ), ! empty( $settings['auto_sync'] ) ? '1' : '0' );
	foreach ( $esx_roles as $esx_role ) {
		printf( '<input type="hidden" name="%1$s[sync_roles][]" value="%2$s" />', esc_attr( $esx_opt ), esc_attr( $esx_role ) );
	}
	printf( '<input type="hidden" name="%1$s[cart_form]" value="1" />', esc_attr( $esx_opt ) );
};

/** A UTC MySQL datetime in the site's own format and timezone. */
$esx_when = function ( $utc ) use ( $esx_date_fmt ) {
	$ts = $utc ? strtotime( (string) $utc . ' UTC' ) : 0;
	return $ts ? wp_date( $esx_date_fmt, $ts ) : '';
};

/** "5 minutes ago" / "in 20 minutes" for a UTC MySQL datetime. */
$esx_ago = function ( $utc ) {
	$ts = $utc ? strtotime( (string) $utc . ' UTC' ) : 0;
	if ( ! $ts ) {
		return '';
	}
	$diff = human_time_diff( $ts, time() );
	/* translators: %s: a length of time, e.g. "5 minutes" */
	return $ts > time() ? sprintf( __( 'in %s', 'emailsendx-sync' ), $diff ) : sprintf( __( '%s ago', 'emailsendx-sync' ), $diff );
};

/** A cart's value: the stored amount, or the saved display total for carts from before amounts were stored. */
$esx_value = function ( $row, $contents ) {
	if ( (float) $row['cart_value'] > 0 ) {
		return EmailSendX_Cart::admin_money( (float) $row['cart_value'], (string) $row['currency'] );
	}
	return '' !== $contents['total'] ? $contents['total'] : '—';
};

$esx_flash = get_transient( EmailSendX_Cart::TEST_TRANSIENT );
if ( is_array( $esx_flash ) ) {
	delete_transient( EmailSendX_Cart::TEST_TRANSIENT );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$esx_saved = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'];

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$esx_cart_id = isset( $_GET['cart'] ) ? (int) $_GET['cart'] : 0;
$esx_cart    = $esx_cart_id > 0 ? EmailSendX_Cart::get_cart( $esx_cart_id ) : null;

if ( is_array( $esx_flash ) && ! empty( $esx_flash['message'] ) ) : ?>
	<p class="esx-cart-result <?php echo ! empty( $esx_flash['ok'] ) ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo esc_html( $esx_flash['message'] ); ?></p>
<?php elseif ( $esx_saved ) : ?>
	<p class="esx-cart-result is-ok" role="status"><?php echo esc_html__( 'Settings saved.', 'emailsendx-sync' ); ?></p>
<?php endif;

/* ═══ Single cart ══════════════════════════════════════════════════════ */
if ( $esx_cart_id > 0 ) :
	if ( ! $esx_cart ) :
		?>
		<div class="esx-card">
			<p><?php echo esc_html__( 'That cart no longer exists. Saved carts are deleted after 30 days, or when the shopper empties their cart.', 'emailsendx-sync' ); ?></p>
			<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( $esx_base_url ); ?>"><?php echo esc_html__( '← All carts', 'emailsendx-sync' ); ?></a>
		</div>
		<?php
		return;
	endif;

	$esx_contents = EmailSendX_Cart::cart_contents( $esx_cart );
	$esx_status   = isset( $esx_labels[ $esx_cart['status'] ] ) ? $esx_labels[ $esx_cart['status'] ] : $esx_labels['active'];
	$esx_name     = trim( $esx_cart['first_name'] . ' ' . $esx_cart['last_name'] );
	$esx_open     = in_array( $esx_cart['status'], array( 'active', 'abandoned', 'skipped', 'failed' ), true );
	$esx_order    = ( (int) $esx_cart['order_id'] > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( (int) $esx_cart['order_id'] ) : null;
	$esx_timeline = EmailSendX_Cart::timeline( $esx_cart, $esx_idle );

	$esx_timeline_text = array(
		'saved'     => __( 'Cart saved', 'emailsendx-sync' ),
		'changed'   => __( 'Cart last changed', 'emailsendx-sync' ),
		'abandoned' => __( 'Counted as abandoned — sent to EmailSendX, reminders started', 'emailsendx-sync' ),
		'returned'  => __( 'Shopper came back and changed the cart', 'emailsendx-sync' ),
		'recovered' => __( 'Order placed after a reminder — recovered', 'emailsendx-sync' ),
		'ordered'   => __( 'Order placed — no reminder was needed', 'emailsendx-sync' ),
		'skipped'   => __( 'EmailSendX would not email this address, so no reminder was sent', 'emailsendx-sync' ),
		'failed'    => __( 'Could not be sent to EmailSendX after three tries', 'emailsendx-sync' ),
		'due'       => __( 'Will count as abandoned if nothing changes', 'emailsendx-sync' ),
	);
	?>
	<p class="esx-carts-back"><a href="<?php echo esc_url( $esx_base_url ); ?>"><?php echo esc_html__( '← All carts', 'emailsendx-sync' ); ?></a></p>

	<div class="esx-card esx-carts-detail-head">
		<div>
			<h2 class="esx-card-title"><?php echo esc_html( '' !== $esx_name ? $esx_name : $esx_cart['email'] ); ?></h2>
			<p class="esx-card-sub">
				<?php if ( '' !== $esx_name ) : ?>
					<a href="mailto:<?php echo esc_attr( $esx_cart['email'] ); ?>"><?php echo esc_html( $esx_cart['email'] ); ?></a> ·
				<?php endif; ?>
				<?php if ( (int) $esx_cart['user_id'] > 0 ) : ?>
					<a href="<?php echo esc_url( get_edit_user_link( (int) $esx_cart['user_id'] ) ); ?>"><?php echo esc_html__( 'Customer account', 'emailsendx-sync' ); ?></a>
				<?php else : ?>
					<?php echo esc_html__( 'Guest', 'emailsendx-sync' ); ?>
				<?php endif; ?>
			</p>
			<p class="esx-help esx-carts-status-help"><?php echo esc_html( $esx_status['help'] ); ?></p>
		</div>
		<span class="esx-pill esx-pill-<?php echo esc_attr( $esx_status['tone'] ); ?>"><?php echo esc_html( $esx_status['label'] ); ?></span>
	</div>

	<div class="esx-carts-detail">
		<div class="esx-card esx-carts-items">
			<h2 class="esx-card-title"><?php echo esc_html__( 'In the cart', 'emailsendx-sync' ); ?></h2>
			<?php if ( empty( $esx_contents['items'] ) ) : ?>
				<p class="esx-help"><?php echo esc_html__( 'The cart contents were not saved.', 'emailsendx-sync' ); ?></p>
			<?php else : ?>
				<ul class="esx-carts-lines">
					<?php foreach ( $esx_contents['items'] as $esx_line ) : ?>
						<li class="esx-carts-line">
							<?php if ( '' !== $esx_line['image_url'] ) : ?>
								<img class="esx-carts-thumb" src="<?php echo esc_url( $esx_line['image_url'] ); ?>" alt="" loading="lazy" width="48" height="48" />
							<?php else : ?>
								<span class="esx-carts-thumb esx-carts-thumb-empty" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="esx-carts-line-name">
								<?php if ( '' !== $esx_line['url'] ) : ?>
									<a href="<?php echo esc_url( $esx_line['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $esx_line['name'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $esx_line['name'] ); ?>
								<?php endif; ?>
								<span class="esx-carts-line-qty">
									<?php
									/* translators: %d: quantity */
									echo esc_html( sprintf( __( 'Qty %d', 'emailsendx-sync' ), $esx_line['qty'] ) );
									?>
								</span>
							</span>
							<span class="esx-carts-line-price"><?php echo esc_html( $esx_line['price'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<dl class="esx-carts-totals">
					<?php if ( ! empty( $esx_contents['coupons'] ) ) : ?>
						<div><dt><?php echo esc_html__( 'Coupons', 'emailsendx-sync' ); ?></dt><dd><?php echo esc_html( implode( ', ', $esx_contents['coupons'] ) ); ?></dd></div>
					<?php endif; ?>
					<div class="esx-carts-total"><dt><?php echo esc_html__( 'Cart total', 'emailsendx-sync' ); ?></dt><dd><?php echo esc_html( $esx_value( $esx_cart, $esx_contents ) ); ?></dd></div>
				</dl>
			<?php endif; ?>
		</div>

		<div class="esx-carts-side">
			<div class="esx-card">
				<h2 class="esx-card-title"><?php echo esc_html__( 'What happened', 'emailsendx-sync' ); ?></h2>
				<ol class="esx-carts-timeline">
					<?php foreach ( $esx_timeline as $esx_step ) : ?>
						<li class="esx-carts-event is-<?php echo esc_attr( $esx_step['tone'] ); ?><?php echo $esx_step['pending'] ? ' is-pending' : ''; ?>">
							<span class="esx-carts-event-text"><?php echo esc_html( isset( $esx_timeline_text[ $esx_step['key'] ] ) ? $esx_timeline_text[ $esx_step['key'] ] : $esx_step['key'] ); ?></span>
							<?php if ( '' !== $esx_step['time'] ) : ?>
								<span class="esx-carts-event-time" title="<?php echo esc_attr( $esx_when( $esx_step['time'] ) ); ?>">
									<?php echo esc_html( $esx_when( $esx_step['time'] ) ); ?> · <?php echo esc_html( $esx_ago( $esx_step['time'] ) ); ?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php if ( 'failed' === $esx_cart['status'] && '' !== (string) $esx_cart['last_error'] ) : ?>
					<p class="esx-cart-result is-error"><?php echo esc_html( $esx_cart['last_error'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $esx_order ) : ?>
				<div class="esx-card">
					<h2 class="esx-card-title"><?php echo esc_html__( 'Order', 'emailsendx-sync' ); ?></h2>
					<p class="esx-carts-order">
						<a href="<?php echo esc_url( $esx_order->get_edit_order_url() ); ?>">
							<?php
							/* translators: %s: order number */
							echo esc_html( sprintf( __( 'Order #%s', 'emailsendx-sync' ), $esx_order->get_order_number() ) );
							?>
						</a>
						<strong><?php echo esc_html( EmailSendX_Cart::admin_money( (float) $esx_order->get_total(), (string) $esx_order->get_currency() ) ); ?></strong>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $esx_open && EmailSendX_Cart::is_valid_token( $esx_cart['token'] ) ) : ?>
				<div class="esx-card">
					<h2 class="esx-card-title"><?php echo esc_html__( 'Link back to this cart', 'emailsendx-sync' ); ?></h2>
					<p class="esx-help"><?php echo esc_html__( 'The same link the reminder emails use. Opening it puts these items back in the cart — use a private window so your own cart is not replaced.', 'emailsendx-sync' ); ?></p>
					<input type="text" class="esx-input esx-carts-link" readonly value="<?php echo esc_attr( EmailSendX_Cart::restore_url( $esx_cart['token'] ) ); ?>" onclick="this.select();" aria-label="<?php echo esc_attr__( 'Link back to this cart', 'emailsendx-sync' ); ?>" />
				</div>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="esx-carts-delete" onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Delete this saved cart? This cannot be undone.', 'emailsendx-sync' ) ) ); ?> );">
				<?php wp_nonce_field( EmailSendX_Cart::NONCE_DELETE ); ?>
				<input type="hidden" name="action" value="emailsendx_cart_delete" />
				<input type="hidden" name="cart_id" value="<?php echo esc_attr( (int) $esx_cart['id'] ); ?>" />
				<button type="submit" class="esx-btn esx-btn-ghost esx-carts-delete-btn"><?php echo esc_html__( 'Delete this cart', 'emailsendx-sync' ); ?></button>
			</form>
		</div>
	</div>
	<?php
	return;
endif;

/* ═══ Overview ═════════════════════════════════════════════════════════ */

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$esx_range  = EmailSendX_Cart::sanitize_range( isset( $_GET['range'] ) ? wp_unslash( $_GET['range'] ) : 30 );
$esx_filter = EmailSendX_Cart::sanitize_filter( isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'all' );
$esx_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$esx_page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$esx_steps    = EmailSendX_Cart::setup_steps();
$esx_done     = count( array_filter( wp_list_pluck( $esx_steps, 'done' ) ) );
$esx_all_done = count( $esx_steps ) === $esx_done;
$esx_overview = EmailSendX_Cart::get_overview( $esx_range );
$esx_list     = EmailSendX_Cart::query_carts(
	array(
		'filter' => $esx_filter,
		'search' => $esx_search,
		'page'   => $esx_page,
		'days'   => $esx_range,
	)
);
$esx_has_any  = $esx_overview['tracked'] > 0 || '' !== $esx_search;
$esx_groups   = EmailSendX_Cart::filter_groups();
$esx_auto_url = $is_ready ? EmailSendX_Cart::sequences_url() : 'https://emailsendx.com';

/** The list URL with the current period / filter / search, plus overrides. */
$esx_url = function ( $overrides = array() ) use ( $esx_base_url, $esx_range, $esx_filter, $esx_search ) {
	$args = array_merge(
		array(
			'range' => 30 === $esx_range ? null : $esx_range,
			'show'  => 'all' === $esx_filter ? null : $esx_filter,
			's'     => '' === $esx_search ? null : $esx_search,
		),
		$overrides
	);
	return add_query_arg( array_filter( $args, static function ( $v ) { return null !== $v && '' !== $v; } ), $esx_base_url );
};

if ( ! $is_ready ) {
	$esx_pill = array( 'warn', __( 'Connect first', 'emailsendx-sync' ) );
} elseif ( $esx_on ) {
	$esx_pill = array( 'ok', __( 'On', 'emailsendx-sync' ) );
} else {
	$esx_pill = array( 'info', __( 'Off', 'emailsendx-sync' ) );
}

$esx_step_copy = array(
	'connect' => array(
		__( 'Connect this site to EmailSendX', 'emailsendx-sync' ),
		__( 'Add your EmailSendX API key so carts can be sent.', 'emailsendx-sync' ),
	),
	'enable'  => array(
		__( 'Turn on cart recovery', 'emailsendx-sync' ),
		__( 'Carts are saved once a shopper enters their email at checkout, or is logged in.', 'emailsendx-sync' ),
	),
	'emails'  => array(
		__( 'Turn on the reminder emails in EmailSendX', 'emailsendx-sync' ),
		__( 'Open EmailSendX and, on the Abandoned cart recovery card, click “Set up reminder emails”, then “Turn on”. The emails already have the link back to the cart and the list of items.', 'emailsendx-sync' ),
	),
	'test'    => array(
		__( 'Send yourself a test cart', 'emailsendx-sync' ),
		__( 'Sends a sample cart for your own address, so the first reminder lands in your inbox within a few minutes.', 'emailsendx-sync' ),
	),
);
?>

<div class="esx-card esx-carts-hero">
	<div class="esx-carts-hero-text">
		<h2 class="esx-card-title"><?php echo esc_html__( 'Abandoned cart recovery', 'emailsendx-sync' ); ?></h2>
		<p class="esx-card-sub"><?php echo esc_html__( 'Shoppers who leave without buying get your reminder emails with a link straight back to their cart. Reminders stop as soon as they order.', 'emailsendx-sync' ); ?></p>
	</div>
	<span class="esx-pill esx-pill-<?php echo esc_attr( $esx_pill[0] ); ?>"><?php echo esc_html( $esx_pill[1] ); ?></span>
</div>

<?php /* ─── Setup checklist ─── */ ?>
<details class="esx-card esx-carts-setup" <?php echo $esx_all_done ? '' : 'open'; ?>>
	<summary class="esx-carts-setup-summary">
		<span class="esx-card-title">
			<?php echo $esx_all_done ? esc_html__( 'Setup complete', 'emailsendx-sync' ) : esc_html__( 'Get set up', 'emailsendx-sync' ); ?>
		</span>
		<span class="esx-carts-setup-count">
			<?php
			/* translators: 1: steps done, 2: total steps */
			echo esc_html( sprintf( __( '%1$d of %2$d done', 'emailsendx-sync' ), $esx_done, count( $esx_steps ) ) );
			?>
		</span>
	</summary>
	<ol class="esx-carts-steps">
		<?php
		$esx_next_shown = false;
		foreach ( $esx_steps as $esx_index => $esx_step ) :
			$esx_key     = $esx_step['key'];
			$esx_is_next = ! $esx_step['done'] && ! $esx_next_shown;
			if ( $esx_is_next ) {
				$esx_next_shown = true;
			}
			?>
			<li class="esx-carts-step <?php echo $esx_step['done'] ? 'is-done' : ( $esx_is_next ? 'is-next' : 'is-later' ); ?>">
				<span class="esx-carts-step-mark" aria-hidden="true"><?php echo $esx_step['done'] ? '✓' : esc_html( (string) ( $esx_index + 1 ) ); ?></span>
				<div class="esx-carts-step-body">
					<span class="esx-carts-step-title">
						<?php echo esc_html( $esx_step_copy[ $esx_key ][0] ); ?>
						<?php if ( $esx_step['done'] ) : ?>
							<span class="screen-reader-text"><?php echo esc_html__( '(done)', 'emailsendx-sync' ); ?></span>
						<?php endif; ?>
					</span>
					<span class="esx-carts-step-sub">
						<?php
						if ( 'enable' === $esx_key && $esx_step['done'] ) {
							/* translators: 1: idle time, e.g. "1 hour"; 2: who gets reminders */
							echo esc_html( sprintf( __( 'On. A cart counts as abandoned after %1$s · %2$s.', 'emailsendx-sync' ), $esx_idle_labels[ $esx_idle ], $esx_modes[ $esx_mode ][0] ) );
						} else {
							echo esc_html( $esx_step_copy[ $esx_key ][1] );
						}
						?>
					</span>
				</div>
				<div class="esx-carts-step-action">
					<?php if ( 'connect' === $esx_key && ! $esx_step['done'] ) : ?>
						<a class="esx-btn esx-btn-primary" href="<?php echo esc_url( EmailSendX_Admin::get_admin_url( 'settings' ) ); ?>"><?php echo esc_html__( 'Open Settings', 'emailsendx-sync' ); ?></a>
					<?php elseif ( 'enable' === $esx_key && ! $esx_step['done'] && $is_ready ) : ?>
						<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
							<?php settings_fields( 'emailsendx_sync_group' ); ?>
							<?php $esx_carry(); ?>
							<input type="hidden" name="<?php echo esc_attr( $esx_opt ); ?>[cart_enabled]" value="1" />
							<input type="hidden" name="<?php echo esc_attr( $esx_opt ); ?>[cart_idle_minutes]" value="<?php echo esc_attr( $esx_idle ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $esx_opt ); ?>[cart_consent_mode]" value="<?php echo esc_attr( $esx_mode ); ?>" />
							<button type="submit" class="esx-btn esx-btn-primary"><?php echo esc_html__( 'Turn on', 'emailsendx-sync' ); ?></button>
						</form>
					<?php elseif ( 'enable' === $esx_key && $esx_step['done'] ) : ?>
						<a class="esx-updates-link" href="#esx-cart"><?php echo esc_html__( 'Change', 'emailsendx-sync' ); ?></a>
					<?php elseif ( 'emails' === $esx_key && $esx_on && $is_ready ) : ?>
						<a class="esx-btn <?php echo $esx_step['done'] ? 'esx-btn-secondary' : 'esx-btn-primary'; ?>" href="<?php echo esc_url( $esx_auto_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open EmailSendX', 'emailsendx-sync' ); ?></a>
					<?php elseif ( 'test' === $esx_key && $esx_on && $is_ready ) : ?>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
							<?php wp_nonce_field( EmailSendX_Cart::NONCE_TEST ); ?>
							<input type="hidden" name="action" value="emailsendx_cart_test" />
							<button type="submit" class="esx-btn esx-btn-secondary"><?php echo $esx_step['done'] ? esc_html__( 'Send again', 'emailsendx-sync' ) : esc_html__( 'Send a test cart', 'emailsendx-sync' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php if ( $esx_on && $is_ready && ! $esx_steps[2]['done'] ) : ?>
		<p class="esx-help esx-carts-setup-note"><?php echo esc_html__( 'Step 3 is ticked automatically: once the automation is on in EmailSendX, send a test cart and this page will see it.', 'emailsendx-sync' ); ?></p>
	<?php endif; ?>
</details>

<?php /* ─── Results ─── */ ?>
<?php if ( $esx_on || $esx_has_any ) : ?>
	<div class="esx-carts-results-head">
		<h2 class="esx-carts-heading"><?php echo esc_html__( 'Results', 'emailsendx-sync' ); ?></h2>
		<div class="esx-carts-range" role="group" aria-label="<?php echo esc_attr__( 'Period', 'emailsendx-sync' ); ?>">
			<?php foreach ( array( 7 => __( 'Last 7 days', 'emailsendx-sync' ), 30 => __( 'Last 30 days', 'emailsendx-sync' ) ) as $esx_days => $esx_days_label ) : ?>
				<a class="esx-carts-chip <?php echo $esx_range === $esx_days ? 'is-active' : ''; ?>" href="<?php echo esc_url( $esx_url( array( 'range' => 30 === $esx_days ? null : $esx_days ) ) ); ?>" <?php echo $esx_range === $esx_days ? 'aria-current="true"' : ''; ?>><?php echo esc_html( $esx_days_label ); ?></a>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="esx-carts-stats">
		<div class="esx-carts-stat">
			<span class="esx-carts-stat-label"><?php echo esc_html__( 'Carts saved', 'emailsendx-sync' ); ?></span>
			<span class="esx-carts-stat-value"><?php echo esc_html( number_format_i18n( $esx_overview['tracked'] ) ); ?></span>
			<span class="esx-carts-stat-sub"><?php echo esc_html__( 'Shoppers whose email is known', 'emailsendx-sync' ); ?></span>
		</div>
		<div class="esx-carts-stat">
			<span class="esx-carts-stat-label"><?php echo esc_html__( 'Abandoned', 'emailsendx-sync' ); ?></span>
			<span class="esx-carts-stat-value"><?php echo esc_html( number_format_i18n( $esx_overview['abandoned'] ) ); ?></span>
			<span class="esx-carts-stat-sub">
				<?php
				/* translators: %s: money amount */
				echo esc_html( sprintf( __( '%s still waiting to be recovered', 'emailsendx-sync' ), EmailSendX_Cart::admin_money( $esx_overview['open_value'] ) ) );
				?>
			</span>
		</div>
		<div class="esx-carts-stat">
			<span class="esx-carts-stat-label"><?php echo esc_html__( 'Recovered orders', 'emailsendx-sync' ); ?></span>
			<span class="esx-carts-stat-value"><?php echo esc_html( number_format_i18n( $esx_overview['recovered'] ) ); ?></span>
			<span class="esx-carts-stat-sub">
				<?php
				/* translators: %d: percentage */
				echo esc_html( sprintf( __( '%d%% of abandoned carts', 'emailsendx-sync' ), $esx_overview['rate'] ) );
				?>
			</span>
		</div>
		<div class="esx-carts-stat is-money">
			<span class="esx-carts-stat-label"><?php echo esc_html__( 'Recovered revenue', 'emailsendx-sync' ); ?></span>
			<span class="esx-carts-stat-value"><?php echo esc_html( EmailSendX_Cart::admin_money( $esx_overview['revenue'] ) ); ?></span>
			<span class="esx-carts-stat-sub"><?php echo esc_html__( 'Orders placed after a reminder', 'emailsendx-sync' ); ?></span>
		</div>
	</div>

	<?php /* ─── Carts table ─── */ ?>
	<div class="esx-card esx-carts-list">
		<div class="esx-carts-toolbar">
			<div class="esx-carts-filters" role="group" aria-label="<?php echo esc_attr__( 'Show', 'emailsendx-sync' ); ?>">
				<?php foreach ( $esx_groups as $esx_slug => $esx_group ) : ?>
					<a class="esx-carts-chip <?php echo $esx_filter === $esx_slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( $esx_url( array( 'show' => 'all' === $esx_slug ? null : $esx_slug ) ) ); ?>" <?php echo $esx_filter === $esx_slug ? 'aria-current="true"' : ''; ?>>
						<?php echo esc_html( $esx_group['label'] ); ?>
						<span class="esx-carts-chip-count"><?php echo esc_html( number_format_i18n( isset( $esx_list['counts'][ $esx_slug ] ) ? $esx_list['counts'][ $esx_slug ] : 0 ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="esx-carts-search" role="search">
				<input type="hidden" name="page" value="<?php echo esc_attr( EmailSendX_Admin::MENU_SLUG ); ?>" />
				<input type="hidden" name="tab" value="carts" />
				<?php if ( 30 !== $esx_range ) : ?>
					<input type="hidden" name="range" value="<?php echo esc_attr( $esx_range ); ?>" />
				<?php endif; ?>
				<?php if ( 'all' !== $esx_filter ) : ?>
					<input type="hidden" name="show" value="<?php echo esc_attr( $esx_filter ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="esx-carts-search"><?php echo esc_html__( 'Search by email or name', 'emailsendx-sync' ); ?></label>
				<input type="search" id="esx-carts-search" name="s" class="esx-input" value="<?php echo esc_attr( $esx_search ); ?>" placeholder="<?php echo esc_attr__( 'Search email or name', 'emailsendx-sync' ); ?>" />
				<button type="submit" class="esx-btn esx-btn-secondary"><?php echo esc_html__( 'Search', 'emailsendx-sync' ); ?></button>
				<?php if ( '' !== $esx_search ) : ?>
					<a class="esx-updates-link" href="<?php echo esc_url( $esx_url( array( 's' => null ) ) ); ?>"><?php echo esc_html__( 'Clear', 'emailsendx-sync' ); ?></a>
				<?php endif; ?>
			</form>
		</div>

		<?php if ( empty( $esx_list['rows'] ) ) : ?>
			<div class="esx-carts-empty">
				<?php if ( '' !== $esx_search ) : ?>
					<p class="esx-carts-empty-title"><?php echo esc_html__( 'No carts match that search.', 'emailsendx-sync' ); ?></p>
				<?php elseif ( 'all' !== $esx_filter ) : ?>
					<p class="esx-carts-empty-title"><?php echo esc_html__( 'No carts here in this period.', 'emailsendx-sync' ); ?></p>
				<?php else : ?>
					<p class="esx-carts-empty-title"><?php echo esc_html__( 'No carts saved yet.', 'emailsendx-sync' ); ?></p>
					<p class="esx-help"><?php echo esc_html__( 'A cart appears here as soon as a shopper adds something and enters their email at checkout, or is logged in.', 'emailsendx-sync' ); ?></p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="esx-carts-table-wrap">
				<table class="esx-table esx-carts-table">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Shopper', 'emailsendx-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Cart', 'emailsendx-sync' ); ?></th>
							<th scope="col" class="esx-carts-num"><?php echo esc_html__( 'Value', 'emailsendx-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Status', 'emailsendx-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last activity', 'emailsendx-sync' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php echo esc_html__( 'Details', 'emailsendx-sync' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $esx_list['rows'] as $esx_row ) :
							$esx_row_contents = EmailSendX_Cart::cart_contents( $esx_row );
							$esx_row_status   = isset( $esx_labels[ $esx_row['status'] ] ) ? $esx_labels[ $esx_row['status'] ] : $esx_labels['active'];
							$esx_row_name     = trim( $esx_row['first_name'] . ' ' . $esx_row['last_name'] );
							$esx_row_url      = add_query_arg( 'cart', (int) $esx_row['id'], $esx_base_url );
							$esx_row_time     = in_array( $esx_row['status'], array( 'recovered', 'ordered' ), true ) && ! empty( $esx_row['closed_at'] ) ? $esx_row['closed_at'] : $esx_row['updated_at'];
							?>
							<tr>
								<td>
									<a class="esx-carts-shopper" href="<?php echo esc_url( $esx_row_url ); ?>">
										<?php echo esc_html( '' !== $esx_row_name ? $esx_row_name : $esx_row['email'] ); ?>
									</a>
									<?php if ( '' !== $esx_row_name ) : ?>
										<span class="esx-carts-muted"><?php echo esc_html( $esx_row['email'] ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( EmailSendX_Cart::cart_summary( $esx_row_contents['items'] ) ); ?>
									<span class="esx-carts-muted">
										<?php
										/* translators: %d: number of items */
										echo esc_html( sprintf( _n( '%d item', '%d items', (int) $esx_row['item_count'], 'emailsendx-sync' ), (int) $esx_row['item_count'] ) );
										?>
									</span>
								</td>
								<td class="esx-carts-num">
									<?php
									if ( 'recovered' === $esx_row['status'] && (float) $esx_row['order_value'] > 0 ) {
										echo esc_html( EmailSendX_Cart::admin_money( (float) $esx_row['order_value'], (string) $esx_row['currency'] ) );
									} else {
										echo esc_html( $esx_value( $esx_row, $esx_row_contents ) );
									}
									?>
								</td>
								<td><span class="esx-pill esx-pill-<?php echo esc_attr( $esx_row_status['tone'] ); ?>" title="<?php echo esc_attr( $esx_row_status['help'] ); ?>"><?php echo esc_html( $esx_row_status['label'] ); ?></span></td>
								<td><span title="<?php echo esc_attr( $esx_when( $esx_row_time ) ); ?>"><?php echo esc_html( $esx_ago( $esx_row_time ) ); ?></span></td>
								<td class="esx-carts-num"><a class="esx-updates-link" href="<?php echo esc_url( $esx_row_url ); ?>"><?php echo esc_html__( 'View', 'emailsendx-sync' ); ?><span class="screen-reader-text"> <?php echo esc_html( $esx_row['email'] ); ?></span></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $esx_list['pages'] > 1 ) : ?>
				<nav class="esx-carts-pages" aria-label="<?php echo esc_attr__( 'Carts pages', 'emailsendx-sync' ); ?>">
					<?php if ( $esx_list['page'] > 1 ) : ?>
						<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( $esx_url( array( 'paged' => $esx_list['page'] - 1 ) ) ); ?>"><?php echo esc_html__( '← Newer', 'emailsendx-sync' ); ?></a>
					<?php endif; ?>
					<span class="esx-carts-muted">
						<?php
						/* translators: 1: current page, 2: total pages */
						echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'emailsendx-sync' ), $esx_list['page'], $esx_list['pages'] ) );
						?>
					</span>
					<?php if ( $esx_list['page'] < $esx_list['pages'] ) : ?>
						<a class="esx-btn esx-btn-secondary" href="<?php echo esc_url( $esx_url( array( 'paged' => $esx_list['page'] + 1 ) ) ); ?>"><?php echo esc_html__( 'Older →', 'emailsendx-sync' ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
		<p class="esx-help esx-carts-retention"><?php echo esc_html__( 'Saved carts are deleted after 30 days.', 'emailsendx-sync' ); ?></p>
	</div>
<?php endif; ?>

<?php /* ─── Settings ─── */ ?>
<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="esx-form" id="esx-cart">
	<?php settings_fields( 'emailsendx_sync_group' ); ?>
	<?php $esx_carry(); ?>

	<div class="esx-card esx-cart">
		<div class="esx-card-head">
			<h2 class="esx-card-title"><?php echo esc_html__( 'Settings', 'emailsendx-sync' ); ?></h2>
			<p class="esx-card-sub"><?php echo esc_html__( 'When a cart counts as abandoned, and which shoppers can be reminded.', 'emailsendx-sync' ); ?></p>
		</div>

		<div class="esx-field">
			<label class="esx-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $esx_opt ); ?>[cart_enabled]" value="1" <?php checked( $esx_on ); ?> />
				<span class="esx-toggle-track" aria-hidden="true"></span>
				<span class="esx-toggle-label"><?php echo esc_html__( 'Recover abandoned carts', 'emailsendx-sync' ); ?></span>
			</label>
			<p class="esx-help" style="margin-left: 48px;"><?php echo esc_html__( 'Saves a shopper’s cart once they enter their email. If they leave, EmailSendX gets the cart and sends your reminder emails.', 'emailsendx-sync' ); ?></p>
		</div>

		<div class="esx-behavior-grid esx-cart-grid">
			<div class="esx-field">
				<label class="esx-label" for="esx-cart-idle"><?php echo esc_html__( 'Count a cart as abandoned after', 'emailsendx-sync' ); ?></label>
				<select id="esx-cart-idle" class="esx-select" name="<?php echo esc_attr( $esx_opt ); ?>[cart_idle_minutes]">
					<?php foreach ( $esx_idle_labels as $esx_minutes => $esx_label ) : ?>
						<option value="<?php echo esc_attr( $esx_minutes ); ?>" <?php selected( $esx_idle, $esx_minutes ); ?>><?php echo esc_html( $esx_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="esx-help"><?php echo esc_html__( 'Time since the shopper last changed their cart. One hour suits most shops.', 'emailsendx-sync' ); ?></p>
			</div>

			<div class="esx-field">
				<span class="esx-label" id="esx-cart-mode-label"><?php echo esc_html__( 'Who can get reminders', 'emailsendx-sync' ); ?></span>
				<div class="esx-cart-modes" role="radiogroup" aria-labelledby="esx-cart-mode-label">
					<?php foreach ( $esx_modes as $esx_mode_slug => $esx_mode_copy ) : ?>
						<label class="esx-cart-mode">
							<input type="radio" name="<?php echo esc_attr( $esx_opt ); ?>[cart_consent_mode]" value="<?php echo esc_attr( $esx_mode_slug ); ?>" <?php checked( $esx_mode, $esx_mode_slug ); ?> />
							<span>
								<span class="esx-cart-mode-title"><?php echo esc_html( $esx_mode_copy[0] ); ?></span>
								<span class="esx-cart-mode-sub"><?php echo esc_html( $esx_mode_copy[1] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="esx-help"><?php echo esc_html__( 'Which option is right depends on the privacy rules where your customers live — ask your adviser if you are unsure. Shoppers who decline are never saved, and saved carts are deleted after 30 days.', 'emailsendx-sync' ); ?></p>
			</div>
		</div>

		<div class="esx-updates-actions">
			<button type="submit" class="esx-btn esx-btn-primary"><?php echo esc_html__( 'Save settings', 'emailsendx-sync' ); ?></button>
		</div>
	</div>
</form>
