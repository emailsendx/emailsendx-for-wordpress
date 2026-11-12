<?php
/**
 * Tests for the parts of abandoned-cart recovery that need no WordPress:
 * who may be tracked, what is sent to EmailSendX, and how the settings
 * are saved.
 *
 *     php tools/tests/run.php
 *
 * Plain PHP, no test framework — the plugin ships none. WordPress
 * functions the code under test calls are replaced by the small stand-ins
 * below. Exits 1 if any check fails. Lives under tools/, so it is never
 * part of the install zip. ShaonPro.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EMAILSENDX_SYNC_OPT_SETTINGS', 'emailsendx_sync_settings' );
define( 'EMAILSENDX_SYNC_OPT_STATE', 'emailsendx_sync_state' );
define( 'EMAILSENDX_SYNC_FILE', __FILE__ );

/* ─── Stand-ins for WordPress ──────────────────────────────────────── */

$GLOBALS['esx_test_settings'] = array();

function emailsendx_sync_get_settings() {
	return array_merge(
		array(
			'api_base'          => 'https://emailsendx.com',
			'api_key'           => 'esx_live_key',
			'default_list_id'   => 'list_1',
			'auto_sync'         => true,
			'sync_role'         => '',
			'sync_roles'        => array(),
			'cart_enabled'      => false,
			'cart_idle_minutes' => 60,
			'cart_consent_mode' => 'notice',
		),
		$GLOBALS['esx_test_settings']
	);
}
function emailsendx_sync_is_configured() {
	return true;
}
function home_url( $path = '' ) {
	return 'https://shop.test' . $path;
}
function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}
function esc_url_raw( $url ) {
	return $url;
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/' );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

require dirname( __DIR__, 2 ) . '/includes/class-emailsendx-cart.php';
require dirname( __DIR__, 2 ) . '/includes/class-emailsendx-settings.php';

/* ─── Tiny harness ─────────────────────────────────────────────────── */

$esx_failures = 0;
$esx_checks   = 0;

function check( $label, $condition ) {
	global $esx_failures, $esx_checks;
	++$esx_checks;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	++$esx_failures;
	echo "  FAIL  {$label}\n";
}
function same( $label, $expected, $actual ) {
	check( $label, $expected === $actual );
	if ( $expected !== $actual ) {
		echo '        expected: ' . var_export( $expected, true ) . "\n";
		echo '        actual:   ' . var_export( $actual, true ) . "\n";
	}
}
function section( $title ) {
	echo "\n{$title}\n";
}

/* ─── Who may be tracked ───────────────────────────────────────────── */

section( 'can_track — "notice": everyone unless they said no' );
check( 'guest, no choice yet', EmailSendX_Cart::can_track( 'notice', '', 0 ) );
check( 'guest who clicked "No thanks" is not tracked', ! EmailSendX_Cart::can_track( 'notice', 'no', 0 ) );
check( 'logged-in customer', EmailSendX_Cart::can_track( 'notice', '', 7 ) );
check( 'logged-in customer who declined is not tracked', ! EmailSendX_Cart::can_track( 'notice', 'no', 7 ) );

section( 'can_track — "checkbox": only after ticking the box' );
check( 'guest who has not ticked is not tracked', ! EmailSendX_Cart::can_track( 'checkbox', '', 0 ) );
check( 'guest who ticked', EmailSendX_Cart::can_track( 'checkbox', 'yes', 0 ) );
check( 'guest who unticked is not tracked', ! EmailSendX_Cart::can_track( 'checkbox', 'no', 0 ) );
check( 'a logged-in customer must tick too', ! EmailSendX_Cart::can_track( 'checkbox', '', 7 ) );

section( 'can_track — "customers": logged-in only' );
check( 'guests are never tracked', ! EmailSendX_Cart::can_track( 'customers', '', 0 ) );
check( 'guests are never tracked, even after a yes', ! EmailSendX_Cart::can_track( 'customers', 'yes', 0 ) );
check( 'logged-in customer', EmailSendX_Cart::can_track( 'customers', '', 7 ) );
check( 'logged-in customer who declined is not tracked', ! EmailSendX_Cart::can_track( 'customers', 'no', 7 ) );

/* ─── Setting values ───────────────────────────────────────────────── */

section( 'sanitize_idle / sanitize_mode' );
same( 'a listed idle time is kept', 120, EmailSendX_Cart::sanitize_idle( '120' ) );
same( 'an unlisted idle time becomes one hour', 60, EmailSendX_Cart::sanitize_idle( 5 ) );
same( 'garbage becomes one hour', 60, EmailSendX_Cart::sanitize_idle( 'soon' ) );
same( 'a known mode is kept', 'checkbox', EmailSendX_Cart::sanitize_mode( 'checkbox' ) );
same( 'an unknown mode becomes "notice"', 'notice', EmailSendX_Cart::sanitize_mode( 'everyone-always' ) );
same( 'a non-string mode becomes "notice"', 'notice', EmailSendX_Cart::sanitize_mode( array( 'customers' ) ) );

section( 'the feature is off until switched on' );
check( 'default is disabled', ! EmailSendX_Cart::is_enabled() );
$GLOBALS['esx_test_settings'] = array( 'cart_enabled' => true );
check( 'enabled once the setting is on', EmailSendX_Cart::is_enabled() );
check( 'but inactive without WooCommerce', ! EmailSendX_Cart::is_active() );
$GLOBALS['esx_test_settings'] = array();

/* ─── Restore token and link ───────────────────────────────────────── */

section( 'restore token and link' );
check( '32 letters and digits is a token', EmailSendX_Cart::is_valid_token( 'aB3dE6gH9jK2mN5pQ8sT1vW4yZ7bC0dF' ) );
check( 'too short is rejected', ! EmailSendX_Cart::is_valid_token( 'abc123' ) );
check( 'punctuation is rejected', ! EmailSendX_Cart::is_valid_token( "aB3dE6gH9jK2mN5pQ8sT1vW4yZ7bC0d'" ) );
check( 'SQL-ish input is rejected', ! EmailSendX_Cart::is_valid_token( "' OR 1=1 -- ...................." ) );
check( 'an array is rejected', ! EmailSendX_Cart::is_valid_token( array( 'x' ) ) );
same(
	'the link is the shop home page plus ?esx_cart=<token>',
	'https://shop.test/?esx_cart=aB3dE6gH9jK2mN5pQ8sT1vW4yZ7bC0dF',
	EmailSendX_Cart::restore_url( 'aB3dE6gH9jK2mN5pQ8sT1vW4yZ7bC0dF' )
);

/* ─── What is sent to EmailSendX ───────────────────────────────────── */

$snapshot = array(
	'items'    => array(
		array(
			'product_id'   => 11,
			'variation_id' => 12,
			'variation'    => array( 'attribute_pa_size' => 'm' ),
			'qty'          => 2,
			'name'         => 'Enamel mug',
			'price'        => '$40.00',
			'url'          => 'https://shop.test/product/mug/',
			'image_url'    => 'https://shop.test/wp-content/uploads/mug.jpg',
		),
		array(
			'product_id' => 13,
			'qty'        => 1,
			'name'       => 'Canvas tote bag',
			'price'      => '$24.00',
			'url'        => 'https://shop.test/product/tote/',
			'image_url'  => '',
		),
		array( 'product_id' => 14, 'qty' => 1, 'name' => '' ),
	),
	'coupons'  => array( 'WELCOME10' ),
	'total'    => '$64.00',
	'value'    => 64.0,
	'currency' => 'USD',
	'count'    => 3,
);
$event = EmailSendX_Cart::build_cart_event( $snapshot, 'https://shop.test/?esx_cart=tok' );

section( 'build_cart_event — the cart_abandoned payload' );
same( 'cart_url is the restore link', 'https://shop.test/?esx_cart=tok', $event['cart_url'] );
same( 'cart_total is the display total', '$64.00', $event['cart_total'] );
same( 'cart_value is the number', 64.0, $event['cart_value'] );
same( 'currency', 'USD', $event['currency'] );
same( 'item_count counts quantities', 3, $event['item_count'] );
same( 'nameless lines are dropped', 2, count( $event['items'] ) );
same(
	'an item carries name, qty, price, url and picture — and nothing else',
	array(
		'name'      => 'Enamel mug',
		'qty'       => 2,
		'price'     => '$40.00',
		'url'       => 'https://shop.test/product/mug/',
		'image_url' => 'https://shop.test/wp-content/uploads/mug.jpg',
	),
	$event['items'][0]
);
check( 'an empty picture is left out', ! array_key_exists( 'image_url', $event['items'][1] ) );
check( 'product ids stay on the site', false === strpos( json_encode( $event ), 'product_id' ) );
check( 'coupon codes stay on the site', false === strpos( json_encode( $event ), 'WELCOME10' ) );

$big = array( 'items' => array(), 'total' => '$1', 'count' => 60 );
for ( $i = 0; $i < 60; $i++ ) {
	$big['items'][] = array(
		'name'      => str_repeat( 'Long product name ', 30 ) . $i,
		'qty'       => 1,
		'price'     => '$1.00',
		'url'       => 'https://shop.test/product/' . str_repeat( 'x', 80 ) . $i,
		'image_url' => 'https://shop.test/wp-content/uploads/' . str_repeat( 'y', 80 ) . $i . '.jpg',
	);
}
$big_event = EmailSendX_Cart::build_cart_event( $big, 'https://shop.test/?esx_cart=tok' );
same( 'at most 20 items are sent', 20, count( $big_event['items'] ) );
same( 'item_count still reports the whole cart', 60, $big_event['item_count'] );
check( 'item names are cut to 200 characters', 200 === strlen( $big_event['items'][0]['name'] ) );
check( 'a worst-case cart stays under EmailSendX\'s 16 KB limit', strlen( json_encode( $big_event ) ) < 16 * 1024 );

$huge = array( 'items' => array(), 'total' => '$1', 'count' => 20 );
for ( $i = 0; $i < 20; $i++ ) {
	$huge['items'][] = array(
		'name'      => 'Product ' . $i,
		'qty'       => 1,
		'url'       => 'https://shop.test/product/' . str_repeat( 'x', 900 ) . $i,
		'image_url' => 'https://shop.test/wp-content/uploads/' . str_repeat( 'y', 900 ) . $i . '.jpg',
	);
}
$huge_event = EmailSendX_Cart::build_cart_event( $huge, 'https://shop.test/?esx_cart=tok' );
check( 'very long links: items are dropped from the end until the payload fits', strlen( json_encode( $huge_event ) ) <= 14000 && count( $huge_event['items'] ) >= 1 && count( $huge_event['items'] ) < 20 );
same( 'the first item is the one kept', 'Product 0', $huge_event['items'][0]['name'] );

$empty = EmailSendX_Cart::build_cart_event( null, 'https://shop.test/' );
same( 'a broken snapshot yields no items, not an error', array(), $empty['items'] );

section( 'build_contact — the contact row' );
same(
	'email is lower-cased; consent source says where it came from',
	array(
		'email'         => 'jane@example.com',
		'consentSource' => 'woocommerce-abandoned-cart',
		'firstName'     => 'Jane',
		'lastName'      => 'Doe',
	),
	EmailSendX_Cart::build_contact( ' Jane@Example.com ', 'Jane', 'Doe' )
);
$nameless = EmailSendX_Cart::build_contact( 'guest@example.com' );
check( 'empty names are omitted, so an existing name is never blanked', ! array_key_exists( 'firstName', $nameless ) && ! array_key_exists( 'lastName', $nameless ) );
check( 'no metadata is sent, so existing custom fields are never replaced', ! array_key_exists( 'metadata', $nameless ) );

/* ─── Saving settings ──────────────────────────────────────────────── */

$settings_class = new ReflectionClass( 'EmailSendX_Settings' );
$sanitizer      = $settings_class->newInstanceWithoutConstructor();

section( 'settings — the cart card' );
$saved = $sanitizer->sanitize(
	array(
		'api_base'          => 'https://emailsendx.com',
		'api_key'           => 'esx_live_key',
		'auto_sync'         => '1',
		'cart_form'         => '1',
		'cart_enabled'      => '1',
		'cart_idle_minutes' => '240',
		'cart_consent_mode' => 'checkbox',
	)
);
same( 'switching it on is saved', true, $saved['cart_enabled'] );
same( 'idle time is saved', 240, $saved['cart_idle_minutes'] );
same( 'consent mode is saved', 'checkbox', $saved['cart_consent_mode'] );

$saved = $sanitizer->sanitize(
	array(
		'cart_form'         => '1',
		'cart_idle_minutes' => '7',
		'cart_consent_mode' => 'spam-everyone',
	)
);
same( 'an unticked box on the cart card switches it off', false, $saved['cart_enabled'] );
same( 'a made-up idle time falls back to one hour', 60, $saved['cart_idle_minutes'] );
same( 'a made-up mode falls back to "notice"', 'notice', $saved['cart_consent_mode'] );

section( 'settings — other forms on the page leave the cart settings alone' );
$GLOBALS['esx_test_settings'] = array(
	'cart_enabled'      => true,
	'cart_idle_minutes' => 120,
	'cart_consent_mode' => 'customers',
);
$saved = $sanitizer->sanitize(
	array(
		'api_base'        => 'https://emailsendx.com',
		'api_key'         => 'esx_live_key',
		'default_list_id' => 'list_2',
		'auto_sync'       => '1',
	)
);
same( 'still on after saving the default list', true, $saved['cart_enabled'] );
same( 'idle time unchanged', 120, $saved['cart_idle_minutes'] );
same( 'consent mode unchanged', 'customers', $saved['cart_consent_mode'] );
same( 'and the other form\'s own value was saved', 'list_2', $saved['default_list_id'] );
$GLOBALS['esx_test_settings'] = array();

/* ─── Result ───────────────────────────────────────────────────────── */

echo "\n{$esx_checks} checks, {$esx_failures} failed\n";
exit( $esx_failures > 0 ? 1 : 0 );
