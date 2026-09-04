<?php
/**
 * Plain PHP tests. Part 1 covers the pure helpers in includes/functions.php
 * over an in-memory store. Part 2 loads the plugin against the fakes in
 * wp-stubs.php, boots it through plugins_loaded, asserts the hooks it
 * registered, and drives every flow through those registered callables.
 *
 * Run: php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../myotp-phone-verification/myotp-phone-verification.php';

$failures = 0;
$count    = 0;

function check( string $name, $expected, $actual ): void {
	global $failures, $count;
	$count++;
	if ( $expected === $actual ) {
		echo "ok   $name\n";
		return;
	}
	$failures++;
	echo "FAIL $name\n     expected: " . var_export( $expected, true ) . "\n     actual:   " . var_export( $actual, true ) . "\n";
}

// ---------------------------------------------------------------- Part 1: pure helpers.

check( 'strip plus', '14155551234', myotp_pv_normalize_phone( '+14155551234' ) );
check( 'strip spaces, dashes, brackets, dots', '14155551234', myotp_pv_normalize_phone( '+1 (415) 555-12.34' ) );
check( 'keep leading zeros', '0044123', myotp_pv_normalize_phone( '00 44 123' ) );
check( 'integer input', '12345678', myotp_pv_normalize_phone( 12345678 ) );
check( 'array input is empty', '', myotp_pv_normalize_phone( array( '1' ) ) );
check( 'null input is empty', '', myotp_pv_normalize_phone( null ) );

check( 'valid 11 digits', true, myotp_pv_is_valid_phone( '14155551234' ) );
check( 'valid 7 digits', true, myotp_pv_is_valid_phone( '1234567' ) );
check( 'valid 15 digits', true, myotp_pv_is_valid_phone( '123456789012345' ) );
check( 'too short', false, myotp_pv_is_valid_phone( '123456' ) );
check( 'too long', false, myotp_pv_is_valid_phone( '1234567890123456' ) );
check( 'leading zero invalid for API', false, myotp_pv_is_valid_phone( '0044123456' ) );
check( 'non-string invalid', false, myotp_pv_is_valid_phone( null ) );

check( 'otp 6 digits', true, myotp_pv_is_valid_otp( '482917' ) );
check( 'otp 3 digits', true, myotp_pv_is_valid_otp( '123' ) );
check( 'otp 8 digits', true, myotp_pv_is_valid_otp( '12345678' ) );
check( 'otp 2 digits', false, myotp_pv_is_valid_otp( '12' ) );
check( 'otp 9 digits', false, myotp_pv_is_valid_otp( '123456789' ) );
check( 'otp letters', false, myotp_pv_is_valid_otp( '12a4' ) );

// Atomic counter.
$now = 1_000_000;
$s   = new MyOTP_Mem_Store();
for ( $i = 1; $i <= 5; $i++ ) {
	$r = myotp_pv_take_slot( $s, 'k', $now + $i, 5, 600 );
	check( "take $i allowed", true, $r['allowed'] );
	check( "take $i count", $i, $r['count'] );
}
$r = myotp_pv_take_slot( $s, 'k', $now + 6, 5, 600 );
check( 'sixth take denied', false, $r['allowed'] );
check( 'sixth retry_after to window end', 595, $r['retry_after'] );
$r = myotp_pv_take_slot( $s, 'k', $now + 601, 5, 600 );
check( 'window rolls over at start+window', true, $r['allowed'] );
check( 'rolled window count 1', 1, $r['count'] );
$s->rows['k'] = 'garbage';
$r            = myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'garbage row replaced', true, $r['allowed'] );
check( 'garbage row count 1', 1, $r['count'] );

// Race: a second taker lands between our read and our cas; we must retry and see its count.
$s = new MyOTP_Mem_Store();
myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
$s->before_cas = function ( $store ) use ( $now ) {
	myotp_pv_take_slot( $store, 'k', $now, 5, 600 );
};
$r = myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'race: lost cas retried', true, $r['allowed'] );
check( 'race: count includes the interleaved take', 3, $r['count'] );
check( 'race: two cas calls made by the racer', 3, $s->cas_calls );

// Race at the cap: interleaved take fills the last slot, so we must be denied.
$s = new MyOTP_Mem_Store();
for ( $i = 0; $i < 4; $i++ ) {
	myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
}
$s->before_cas = function ( $store ) use ( $now ) {
	myotp_pv_take_slot( $store, 'k', $now, 5, 600 );
};
$r = myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'race at cap: denied', false, $r['allowed'] );
check( 'race at cap: count is 5 not 6', 5, json_decode( $s->rows['k'], true )['c'] );

// Race on first insert: add() loses to a concurrent add.
$s = new MyOTP_Mem_Store();
$s->rows['k'] = myotp_pv_json( array( 'c' => 1, 's' => $now ) );
$r            = myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'add loses then cas wins', 2, $r['count'] );

// Release.
$s = new MyOTP_Mem_Store();
myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'release returns true', true, myotp_pv_release_slot( $s, 'k', $now, 600 ) );
check( 'release decrements', 1, json_decode( $s->rows['k'], true )['c'] );
myotp_pv_release_slot( $s, 'k', $now, 600 );
check( 'release floors at zero', false, myotp_pv_release_slot( $s, 'k', $now, 600 ) );
check( 'release on missing key', false, myotp_pv_release_slot( $s, 'nope', $now, 600 ) );
check( 'release on expired window', false, myotp_pv_release_slot( $s, 'k', $now + 601, 600 ) );

// Dimensions with their own windows, best-effort all or nothing.
$s    = new MyOTP_Mem_Store();
$dims = array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p', 3 ), array( 'site', 100, 3600 ) );
for ( $i = 0; $i < 3; $i++ ) {
	$r = myotp_pv_take_send_slots( $s, $dims, $now, 600 );
	check( "dims take $i allowed", true, $r['allowed'] );
}
check( 'dims taken keys carry windows', array( array( 'v', 600 ), array( 'ip', 600 ), array( 'p', 600 ), array( 'site', 3600 ) ), $r['taken'] );
$r = myotp_pv_take_send_slots( $s, $dims, $now, 600 );
check( 'phone cap 3 denies fourth', false, $r['allowed'] );
check( 'denied dimension named', 'p', $r['denied'] );
check( 'denied request released visitor slot', 3, json_decode( $s->rows['v'], true )['c'] );
check( 'denied request released ip slot', 3, json_decode( $s->rows['ip'], true )['c'] );
check( 'denied request did not touch site slot', 3, json_decode( $s->rows['site'], true )['c'] );
// Site window survives a 10-minute rollover.
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p2', 3 ), array( 'site', 4, 3600 ) ), $now + 700, 600 );
check( 'after 10 min: visitor window rolled', 1, json_decode( $s->rows['v'], true )['c'] );
check( 'after 10 min: site window still counting', 4, json_decode( $s->rows['site'], true )['c'] );
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p3', 3 ), array( 'site', 4, 3600 ) ), $now + 701, 600 );
check( 'site cap denies', false, $r['allowed'] );
check( 'site is the denied dimension', 'site', $r['denied'] );
check( 'site denial released visitor slot', 1, json_decode( $s->rows['v'], true )['c'] );
check( 'site denial retry_after runs to the hour', 3600 - 701, $r['retry_after'] );

// Conditional delete contract.
$s = new MyOTP_Mem_Store();
$s->set( 'x', 'old', 60 );
check( 'delete with stale expected value refused', false, $s->delete( 'x', 'other' ) );
check( 'row survives stale delete', 'old', $s->get( 'x' ) );
check( 'delete with matching expected value', true, $s->delete( 'x', 'old' ) );

// Attempt reservation.
$s = new MyOTP_Mem_Store();
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'no pending: not ok', false, $r['ok'] );
check( 'no pending: not locked', false, $r['locked'] );
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'm1', 'attempts' => 0, 'ttl' => 60 ) ), 60 );
for ( $i = 1; $i <= 5; $i++ ) {
	$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
	check( "attempt $i ok", true, $r['ok'] );
	check( "attempt $i counted", $i, $r['attempts'] );
}
$before = $s->get( 'pend' );
$r      = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'sixth attempt locked', true, $r['locked'] );
check( 'pending discarded on lock', null, $s->get( 'pend' ) );
check( 'lock delete was guarded by the value read', array( 'pend', $before ), end( $s->deletes ) );
$s->set( 'pend', 'garbage', 60 );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'garbage pending dropped', null, $s->get( 'pend' ) );
check( 'garbage delete was guarded', array( 'pend', 'garbage' ), end( $s->deletes ) );

// Race on the lock: a fresh pending record written between read and delete must survive.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'old', 'attempts' => 5, 'ttl' => 60 ) ), 60 );
$fresh = myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'new', 'attempts' => 0, 'ttl' => 60 ) );
// Simulate: the stale request read the exhausted record, then a new send replaced it.
$stale_raw = $s->get( 'pend' );
$s->set( 'pend', $fresh, 60 );
check( 'stale guarded delete refused', false, $s->delete( 'pend', $stale_raw ) );
check( 'fresh pending survives stale delete', $fresh, $s->get( 'pend' ) );

// Race on attempts: interleaved reservation must be counted.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'm1', 'attempts' => 3, 'ttl' => 60 ) ), 60 );
$s->before_cas = function ( $store ) {
	myotp_pv_reserve_attempt( $store, 'pend', 5 );
};
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'attempt race: retried and counted', 5, $r['attempts'] );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'attempt race: next is locked', true, $r['locked'] );

// Attempt release.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '1', 'message_id' => 'm', 'attempts' => 2, 'ttl' => 60 ) ), 60 );
check( 'release attempt', true, myotp_pv_release_attempt( $s, 'pend' ) );
check( 'release attempt decrements', 1, json_decode( $s->get( 'pend' ), true )['attempts'] );
myotp_pv_release_attempt( $s, 'pend' );
check( 'release attempt floors at zero', false, myotp_pv_release_attempt( $s, 'pend' ) );
check( 'release attempt on missing', false, myotp_pv_release_attempt( $s, 'nope' ) );

// Verified record and atomic claim.
check( 'verified fresh', '14155551234', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 10, 'state' => 'verified' ), $now, 1800 ) );
check( 'verified at exactly ttl expired', '', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 1800, 'state' => 'verified' ), $now, 1800 ) );
check( 'verified future timestamp rejected', '', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now + 5 ), $now, 1800 ) );
check( 'verified consumed rejected', '', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'consumed:order:1' ), $now, 1800 ) );
check( 'verified legacy string rejected', '', myotp_pv_verified_phone_from( '14155551234', $now, 1800 ) );
$s = new MyOTP_Mem_Store();
check( 'claim on missing', '', myotp_pv_claim_verified( $s, 'ver', 'order:1', $now ) );
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
check( 'first claim wins', '14155551234', myotp_pv_claim_verified( $s, 'ver', 'order:1', $now ) );
check( 'record marked consumed', 'consumed:order:1', json_decode( $s->get( 'ver' ), true )['state'] );
check( 'second claim loses', '', myotp_pv_claim_verified( $s, 'ver', 'order:2', $now ) );
check( 'consumed record reads as unverified', '', myotp_pv_verified_phone_from( json_decode( $s->get( 'ver' ), true ), $now ) );
// Claim race: two orders read "verified", both CAS; only one wins.
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
$won           = array();
$s->before_cas = function ( $store ) use ( $now, &$won ) {
	$won[] = myotp_pv_claim_verified( $store, 'ver', 'order:B', $now );
};
$won[] = myotp_pv_claim_verified( $s, 'ver', 'order:A', $now );
check( 'claim race: exactly one winner', 1, count( array_filter( $won ) ) );
check( 'claim race: B (the interleaved one) won', 'consumed:order:B', json_decode( $s->get( 'ver' ), true )['state'] );
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 1801, 'state' => 'verified' ) ), 1800 );
check( 'claim on expired', '', myotp_pv_claim_verified( $s, 'ver', 'order:3', $now ) );

// Send body shape.
check( 'send body ok', true, myotp_pv_is_send_body( array( 'message_id' => 'abc', 'status' => 'accepted' ) ) );
check( 'send body empty id', false, myotp_pv_is_send_body( array( 'message_id' => '' ) ) );
check( 'send body no id', false, myotp_pv_is_send_body( array( 'status' => 'accepted' ) ) );

// Option sanitisation.
$key      = 'abcdefghijklmnopqrstuvwxyz012345';
$defaults = myotp_pv_default_options();
check( 'empty input yields defaults', $defaults, myotp_pv_sanitize_options( array(), array() ) );
check( 'default site cap 100', 100, $defaults['site_hourly_cap'] );
check( 'valid key stored', $key, myotp_pv_sanitize_options( array( 'api_key' => $key ), array() )['api_key'] );
check( 'invalid key keeps current', $key, myotp_pv_sanitize_options( array( 'api_key' => 'short' ), array( 'api_key' => $key ) )['api_key'] );
check( 'mask keeps current key', $key, myotp_pv_sanitize_options( array( 'api_key' => MYOTP_PV_KEY_MASK ), array( 'api_key' => $key ) )['api_key'] );
check( 'empty key clears', '', myotp_pv_sanitize_options( array( 'api_key' => '' ), array( 'api_key' => $key ) )['api_key'] );
check( 'channel lowercased', 'whatsapp', myotp_pv_sanitize_options( array( 'channel' => 'WhatsApp' ), array() )['channel'] );
check( 'unknown channel falls back to sms', 'sms', myotp_pv_sanitize_options( array( 'channel' => 'pigeon' ), array( 'channel' => 'telegram' ) )['channel'] );
check( 'length 3 falls to 6', 6, myotp_pv_sanitize_options( array( 'otp_length' => '3' ), array() )['otp_length'] );
check( 'validity 59 falls to 300', 300, myotp_pv_sanitize_options( array( 'otp_validity' => '59' ), array() )['otp_validity'] );
check( 'site cap 250', 250, myotp_pv_sanitize_options( array( 'site_hourly_cap' => '250' ), array() )['site_hourly_cap'] );
check( 'site cap 0 keeps current', 100, myotp_pv_sanitize_options( array( 'site_hourly_cap' => '0' ), array() )['site_hourly_cap'] );
check( 'site cap too big keeps current', 100, myotp_pv_sanitize_options( array( 'site_hourly_cap' => '999999' ), array() )['site_hourly_cap'] );
check( 'bad brand keeps current', 'Acme', myotp_pv_sanitize_options( array( 'brand' => 'Acme Shop!' ), array( 'brand' => 'Acme' ) )['brand'] );
check( 'missing checkbox means off', 0, myotp_pv_sanitize_options( array( 'otp_length' => 6 ), array( 'wc_enabled' => 1 ) )['wc_enabled'] );
check( 'mask non-empty', MYOTP_PV_KEY_MASK, myotp_pv_mask_key( $key ) );
check( 'envelope message', 'Insufficient balance', myotp_pv_error_message( array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ), 402 ) );
check( '401 fallback', "The site's MyOTP API key was rejected. Ask the site owner to check it.", myotp_pv_error_message( null, 401 ) );

// ---------------------------------------------------------------- Part 2: boot through hooks and drive them.

myotp_test_reset();
$GLOBALS['myotp_test']['options']['myotp_pv_options'] = array_merge(
	myotp_pv_default_options(),
	array( 'api_key' => $key, 'register_enabled' => 1, 'wc_enabled' => 1 )
);

// Top-level hooks registered by loading the file.
check( 'hook: plugins_loaded -> myotp_pv_init', true, myotp_test_has_hook( 'plugins_loaded', 'myotp_pv_init' ) );
check( 'hook: admin_init -> privacy policy', true, myotp_test_has_hook( 'admin_init', 'myotp_pv_privacy_policy' ) );
check( 'hook: cron myotp_pv_sweep', true, myotp_test_has_hook( 'myotp_pv_sweep', 'myotp_pv_sweep' ) );
check( 'hook: init schedules sweep', true, myotp_test_has_hook( 'init', 'myotp_pv_schedule_sweep' ) );
check( 'hook: activation', true, myotp_test_has_hook( 'activate', 'myotp_pv_activate' ) );
check( 'hook: deactivation', true, myotp_test_has_hook( 'deactivate', 'myotp_pv_deactivate' ) );

// Boot.
myotp_test_do_action( 'plugins_loaded' );
check( 'hook: wp_ajax_nopriv_myotp_pv_send', true, myotp_test_has_hook( 'wp_ajax_nopriv_myotp_pv_send', array( 'MyOTP_PV_Ajax', 'send' ) ) );
check( 'hook: wp_ajax_myotp_pv_send', true, myotp_test_has_hook( 'wp_ajax_myotp_pv_send', array( 'MyOTP_PV_Ajax', 'send' ) ) );
check( 'hook: wp_ajax_nopriv_myotp_pv_verify', true, myotp_test_has_hook( 'wp_ajax_nopriv_myotp_pv_verify', array( 'MyOTP_PV_Ajax', 'verify' ) ) );
check( 'hook: wp_ajax_myotp_pv_test', true, myotp_test_has_hook( 'wp_ajax_myotp_pv_test', array( 'MyOTP_PV_Ajax', 'admin_test' ) ) );
check( 'hook: no nopriv admin test', false, myotp_test_has_hook( 'wp_ajax_nopriv_myotp_pv_test' ) );
check( 'hook: woocommerce_after_checkout_validation', true, myotp_test_has_hook( 'woocommerce_after_checkout_validation', array( 'MyOTP_PV_WooCommerce', 'validate' ) ) );
check( 'hook: woocommerce_checkout_order_created', true, myotp_test_has_hook( 'woocommerce_checkout_order_created', array( 'MyOTP_PV_WooCommerce', 'claim' ) ) );
check( 'hook: woocommerce_after_checkout_billing_form', true, myotp_test_has_hook( 'woocommerce_after_checkout_billing_form', array( 'MyOTP_PV_WooCommerce', 'widget' ) ) );
check( 'hook: registration_errors', true, myotp_test_has_hook( 'registration_errors', array( 'MyOTP_PV_Registration', 'validate' ) ) );
check( 'hook: register_new_user', true, myotp_test_has_hook( 'register_new_user', array( 'MyOTP_PV_Registration', 'save' ) ) );
check( 'hook: user_register is not used', false, myotp_test_has_hook( 'user_register' ) );
check( 'hook: register_form', true, myotp_test_has_hook( 'register_form', array( 'MyOTP_PV_Registration', 'field' ) ) );
check( 'hook: wp_enqueue_scripts', true, myotp_test_has_hook( 'wp_enqueue_scripts', array( 'MyOTP_PV_Widget', 'front_assets' ) ) );
check( 'hook: login_enqueue_scripts', true, myotp_test_has_hook( 'login_enqueue_scripts', array( 'MyOTP_PV_Widget', 'login_assets' ) ) );
check( 'hook: admin_init -> settings register', true, myotp_test_has_hook( 'admin_init', array( 'MyOTP_PV_Settings', 'register' ) ) );
check( 'hook: admin_menu', true, myotp_test_has_hook( 'admin_menu', array( 'MyOTP_PV_Settings', 'menu' ) ) );
check( 'hook: shortcode myotp_verify', true, myotp_test_has_hook( 'shortcode:myotp_verify', array( 'MyOTP_PV_Shortcode', 'render' ) ) );

// Cron scheduling through the registered callables.
myotp_test_do_action( 'init' );
check( 'cron: sweep scheduled daily', 'daily', $GLOBALS['myotp_test']['cron']['myotp_pv_sweep'][1] );
myotp_test_do_action( 'deactivate' );
check( 'cron: deactivation clears sweep', false, wp_next_scheduled( 'myotp_pv_sweep' ) );
myotp_test_do_action( 'activate' );
check( 'cron: activation schedules sweep', true, false !== wp_next_scheduled( 'myotp_pv_sweep' ) );
myotp_test_do_action( 'myotp_pv_sweep' );
check( 'cron: sweep runs', true, true );

function myotp_test_configure(): void {
	myotp_test_reset();
	$GLOBALS['myotp_test']['options']['myotp_pv_options'] = array_merge(
		myotp_pv_default_options(),
		array( 'api_key' => 'abcdefghijklmnopqrstuvwxyz012345', 'register_enabled' => 1 )
	);
	$_COOKIE['myotp_pv_sid'] = str_repeat( 'a', 32 );
}
function myotp_test_send( string $phone ): MyOTP_Test_Exit {
	$_POST = array( 'phone' => $phone, 'nonce' => 'x' );
	return myotp_test_ajax( 'wp_ajax_nopriv_myotp_pv_send' );
}
function myotp_test_verify( string $otp, string $phone = '' ): MyOTP_Test_Exit {
	$_POST = array( 'otp' => $otp, 'phone' => $phone, 'nonce' => 'x' );
	return myotp_test_ajax( 'wp_ajax_nopriv_myotp_pv_verify' );
}
function myotp_test_pending(): ?array {
	foreach ( MyOTP_PV_Store::$instance->rows as $k => $v ) {
		if ( 0 === strpos( $k, 'pending_' ) ) {
			return json_decode( $v, true );
		}
	}
	return null;
}
function myotp_test_counter( string $key ): int {
	$raw = MyOTP_PV_Store::$instance->get( $key );
	return null === $raw ? 0 : (int) json_decode( $raw, true )['c'];
}

// Nonce failure stops everything before any HTTP call.
myotp_test_configure();
$GLOBALS['myotp_test']['nonce_ok'] = false;
$r                                 = myotp_test_send( '14155551234' );
check( 'send: bad nonce refused', false, $r->success );
check( 'send: bad nonce status 403', 403, $r->status );
check( 'send: bad nonce made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );
check( 'verify: bad nonce refused', false, myotp_test_verify( '123456' )->success );

// No key configured.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['api_key'] = '';
$r = myotp_test_send( '14155551234' );
check( 'send: no key configured is an error', false, $r->success );
check( 'send: no key made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );

myotp_test_configure();
check( 'send: bad phone 400', 400, myotp_test_send( '+0 12' )->status );

// Success writes pending state and the key goes only in the header.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-1', 'status' => 'accepted' ) );
$r = myotp_test_send( '+1 (415) 555-1234' );
check( 'send: success', true, $r->success );
check( 'send: normalised phone echoed', '14155551234', $r->data['phone'] );
$call = $GLOBALS['myotp_test']['http_log'][0];
check( 'send: url', 'https://api.myotp.app/generate_otp', $call['url'] );
check( 'send: X-API-Key header', 'abcdefghijklmnopqrstuvwxyz012345', $call['args']['headers']['X-API-Key'] );
$payload = json_decode( $call['args']['body'], true );
check( 'send: force_send false', false, $payload['force_send'] );
check( 'send: key not in response', false, strpos( json_encode( $r->data ), 'abcdefghijklmnopqrstuvwxyz012345' ) );
check( 'send: pending stored with message id', 'msg-1', myotp_test_pending()['message_id'] );
check( 'send: pending attempts zero', 0, myotp_test_pending()['attempts'] );
check( 'send: site counter taken', 1, myotp_test_counter( 'send_site' ) );

// 2xx without message_id is not reported as sent.
myotp_test_configure();
myotp_test_http( 200, array( 'status' => 'accepted' ) );
check( 'send: 200 without message_id is an error', false, myotp_test_send( '14155551234' )->success );

// API error surfaced; 4xx keeps the slot.
myotp_test_configure();
myotp_test_http( 402, array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ) );
$r = myotp_test_send( '14155551234' );
check( 'send: 402 surfaced', 'Insufficient balance', $r->data['message'] );
check( 'send: 4xx keeps the slot consumed', 1, myotp_test_counter( 'send_p_14155551234' ) );

// Transport failure refunds every dimension including the site counter.
myotp_test_configure();
myotp_test_http( 'wp_error', null );
$r = myotp_test_send( '14155551234' );
check( 'send: transport error surfaced', 'Could not reach the verification service. Try again in a moment.', $r->data['message'] );
check( 'send: transport error refunded phone slot', 0, myotp_test_counter( 'send_p_14155551234' ) );
check( 'send: transport error refunded site slot', 0, myotp_test_counter( 'send_site' ) );

// 409: existing code reused and the attempt count is NOT reset.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-2' ) );
myotp_test_send( '14155551234' );
for ( $i = 0; $i < 3; $i++ ) {
	myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
	myotp_test_verify( '000000' );
}
check( '409: three attempts used before resend', 3, myotp_test_pending()['attempts'] );
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
$r = myotp_test_send( '14155551234' );
check( '409: soft success', true, $r->success );
check( '409: message', 'A code is already on its way to this number. Enter it below.', $r->data['message'] );
check( '409: attempts kept', 3, myotp_test_pending()['attempts'] );
check( '409: message id kept', 'msg-2', myotp_test_pending()['message_id'] );
for ( $i = 0; $i < 2; $i++ ) {
	myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
	myotp_test_verify( '000000' );
}
check( '409: sixth guess after resend is locked', 429, myotp_test_verify( '000000' )->status );
// 409 with no pending record for that number creates one.
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
myotp_test_send( '14155551234' );
check( '409 without pending: record created', '14155551234', myotp_test_pending()['phone'] );
check( '409 without pending: no message id', '', myotp_test_pending()['message_id'] );

// Rate limits: destination 3, visitor 5, IP 10 across rotated cookies, site cap.
myotp_test_configure();
for ( $i = 0; $i < 3; $i++ ) {
	myotp_test_http( 200, array( 'message_id' => "m$i" ) );
	myotp_test_send( '14155551234' );
}
$r = myotp_test_send( '14155551234' );
check( 'limit: 4th send to same number refused', 429, $r->status );
check( 'limit: no HTTP call on refusal', 3, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_http( 200, array( 'message_id' => 'm4' ) );
myotp_test_send( '14155550001' );
myotp_test_http( 200, array( 'message_id' => 'm5' ) );
myotp_test_send( '14155550002' );
check( 'limit: 6th send by one visitor refused', false, myotp_test_send( '14155550003' )->success );
for ( $i = 0; $i < 5; $i++ ) {
	$_COOKIE['myotp_pv_sid'] = str_repeat( 'b', 32 );
	myotp_test_http( 200, array( 'message_id' => "n$i" ) );
	check( "limit: rotated cookie send $i allowed until ip cap", true, myotp_test_send( '1415555100' . $i )->success );
}
$_COOKIE['myotp_pv_sid'] = str_repeat( 'c', 32 );
check( 'limit: 11th send from one IP refused despite new cookie', false, myotp_test_send( '14155552000' )->success );
unset( $_COOKIE['myotp_pv_sid'] );
check( 'limit: no cookie at all still refused by ip', false, myotp_test_send( '14155552001' )->success );
check( 'limit: ip refusals made no HTTP call', 10, count( $GLOBALS['myotp_test']['http_log'] ) );

// Site-wide cap: new IPs, new cookies, new numbers, still bounded.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['site_hourly_cap'] = 4;
for ( $i = 0; $i < 4; $i++ ) {
	$_SERVER['REMOTE_ADDR']  = '198.51.100.' . ( 10 + $i );
	$_COOKIE['myotp_pv_sid'] = str_repeat( (string) $i, 32 );
	myotp_test_http( 200, array( 'message_id' => "s$i" ) );
	check( "site cap: send $i from a fresh ip allowed", true, myotp_test_send( '4477000000' . $i )->success );
}
$_SERVER['REMOTE_ADDR']  = '198.51.100.99';
$_COOKIE['myotp_pv_sid'] = str_repeat( '9', 32 );
$r                       = myotp_test_send( '447700009999' );
check( 'site cap: 5th send from yet another ip refused', 429, $r->status );
check( 'site cap: refusal released the fresh visitor slot', 0, myotp_test_counter( 'send_v_c_' . str_repeat( '9', 32 ) ) );
check( 'site cap: no HTTP call', 4, count( $GLOBALS['myotp_test']['http_log'] ) );
// Filter raises the cap.
add_filter( 'myotp_pv_site_hourly_cap', function ( $cap ) { return $cap + 1; } );
myotp_test_http( 200, array( 'message_id' => 's5' ) );
check( 'site cap: filter raises the ceiling', true, myotp_test_send( '447700009999' )->success );
$GLOBALS['myotp_test']['hooks']['myotp_pv_site_hourly_cap'] = array();

// Client IP filter.
myotp_test_configure();
add_filter( 'myotp_pv_client_ip', function ( $ip ) { return '10.0.0.1'; } );
check( 'ip filter applied', '10.0.0.1', MyOTP_PV_Session::client_ip() );
$GLOBALS['myotp_test']['hooks']['myotp_pv_client_ip'] = array();
check( 'ip without filter is REMOTE_ADDR', '203.0.113.5', MyOTP_PV_Session::client_ip() );

// Verify: mismatch before reservation, transport does not consume, provider answers do.
myotp_test_configure();
check( 'verify: nothing pending', 'Request a code first.', myotp_test_verify( '123456' )->data['message'] );
myotp_test_http( 200, array( 'message_id' => 'msg-9' ) );
myotp_test_send( '14155551234' );
check( 'verify: bad code shape 400', 400, myotp_test_verify( '12' )->status );
check( 'verify: bad code shape did not consume', 0, myotp_test_pending()['attempts'] );
check( 'verify: changed number refused', 'The number changed after the code was sent. Send a new code.', myotp_test_verify( '123456', '14155559999' )->data['message'] );
check( 'verify: mismatch did not consume an attempt', 0, myotp_test_pending()['attempts'] );
myotp_test_http( 'wp_error', null );
$r = myotp_test_verify( '123456' );
check( 'verify: transport error surfaced', 'Could not reach the verification service. Try again in a moment.', $r->data['message'] );
check( 'verify: transport error did not consume an attempt', 0, myotp_test_pending()['attempts'] );
myotp_test_http( 500, '<html>oops</html>' );
$r = myotp_test_verify( '123456' );
check( 'verify: 500 surfaced', 'The verification service returned an error.', $r->data['message'] );
check( 'verify: 500 consumed an attempt', 1, myotp_test_pending()['attempts'] );
for ( $i = 1; $i <= 4; $i++ ) {
	myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
	$r = myotp_test_verify( '000000' );
	check( "verify: wrong code $i surfaced", 'Invalid OTP', $r->data['message'] );
}
check( 'verify: remaining after 5 answers', 0, $r->data['remaining'] );
$r = myotp_test_verify( '000000' );
check( 'verify: 6th attempt locked', 429, $r->status );
check( 'verify: lock made no extra HTTP call', 7, count( $GLOBALS['myotp_test']['http_log'] ) );
check( 'verify: after lock needs new send', 'Request a code first.', myotp_test_verify( '000000' )->data['message'] );

// Verify success writes a store record in state verified.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-10' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify: success', true, $r->success );
$vcall = json_decode( $GLOBALS['myotp_test']['http_log'][1]['args']['body'], true );
check( 'verify: message_id sent to API', 'msg-10', $vcall['message_id'] );
check( 'verify: session reports verified', '14155551234', MyOTP_PV_Session::verified_phone() );
$vkey = 'verified_c_' . str_repeat( 'a', 32 );
$rec  = json_decode( MyOTP_PV_Store::$instance->get( $vkey ), true );
check( 'verify: record state verified', 'verified', $rec['state'] );
check( 'verify: record has timestamp', true, abs( time() - $rec['at'] ) < 5 );
$rec['at'] = time() - 1801;
MyOTP_PV_Store::$instance->set( $vkey, myotp_pv_json( $rec ), 60 );
check( 'verify: record older than 30 min is expired', '', MyOTP_PV_Session::verified_phone() );
check( 'verify: pending cleared after success', 'Request a code first.', myotp_test_verify( '482917' )->data['message'] );

// Expired status drops the pending record.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-11' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'expired', 'message' => 'OTP expired' ) );
check( 'verify: expired surfaced', 'OTP expired', myotp_test_verify( '111111' )->data['message'] );
check( 'verify: expired code needs new send', 'Request a code first.', myotp_test_verify( '111111' )->data['message'] );

// Admin test through its hook: capability enforced.
myotp_test_configure();
$_POST = array( 'phone' => '14155551234', 'nonce' => 'x' );
check( 'admin test: no capability 403', 403, myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->status );
$GLOBALS['myotp_test']['can_manage'] = true;
myotp_test_http( 200, array( 'message_id' => 'msg-t', 'status' => 'accepted', 'cost' => 1 ) );
$r = myotp_test_ajax( 'wp_ajax_myotp_pv_test' );
check( 'admin test: success', true, $r->success );
check( 'admin test: message', 'Sent to 14155551234. Message ID msg-t.', $r->data['message'] );

// Registration through registration_errors and register_new_user.
function myotp_test_register_validate(): WP_Error {
	$errors = new WP_Error();
	return apply_filters( 'registration_errors', $errors, 'bob', 'bob@example.test' );
}
myotp_test_configure();
check( 'register: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_register_validate()->get_error_codes() );
myotp_test_do_action( 'register_new_user', 42 );
check( 'register: save without a passed validation stamps nothing', '', get_user_meta( 42, 'myotp_verified_phone', true ) );
MyOTP_PV_Session::set_verified( '14155551234' );
$_POST['myotp_pv_phone'] = '';
check( 'register: empty submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), myotp_test_register_validate()->get_error_codes() );
$_POST['myotp_pv_phone'] = '+1 415 555 9999';
check( 'register: different submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), myotp_test_register_validate()->get_error_codes() );
myotp_test_do_action( 'register_new_user', 43 );
check( 'register: failed validation does not stamp', '', get_user_meta( 43, 'myotp_verified_phone', true ) );
// Matching phone but another error already present: flag must not be set.
$_POST['myotp_pv_phone'] = '+1 (415) 555-1234';
$errors                  = new WP_Error();
$errors->add( 'username_exists', 'taken' );
$errors = apply_filters( 'registration_errors', $errors, 'bob', 'bob@example.test' );
check( 'register: other error leaves ours out', array( 'username_exists' ), $errors->get_error_codes() );
myotp_test_do_action( 'register_new_user', 44 );
check( 'register: no flag when another error was present', '', get_user_meta( 44, 'myotp_verified_phone', true ) );
check( 'register: verification untouched by the failed pass', '14155551234', MyOTP_PV_Session::verified_phone() );
// Clean pass.
check( 'register: matching phone passes', array(), myotp_test_register_validate()->get_error_codes() );
// A different user creation in the same request with a different posted phone must not be stamped.
$saved_post              = $_POST;
$_POST['myotp_pv_phone'] = '14155550000';
myotp_test_do_action( 'register_new_user', 45 );
check( 'register: other phone not stamped', '', get_user_meta( 45, 'myotp_verified_phone', true ) );
check( 'register: verification still unclaimed', '14155551234', MyOTP_PV_Session::verified_phone() );
$_POST = $saved_post;
myotp_test_do_action( 'register_new_user', 46 );
check( 'register: passed validation stamps meta', '14155551234', get_user_meta( 46, 'myotp_verified_phone', true ) );
check( 'register: verification claimed by user', 'consumed:user:46', json_decode( MyOTP_PV_Store::$instance->get( 'verified_c_' . str_repeat( 'a', 32 ) ), true )['state'] );
check( 'register: verified consumed after save', '', MyOTP_PV_Session::verified_phone() );
myotp_test_do_action( 'register_new_user', 47 );
check( 'register: flag is single use', '', get_user_meta( 47, 'myotp_verified_phone', true ) );

// Checkout through woocommerce_after_checkout_validation and woocommerce_checkout_order_created.
function myotp_test_checkout_validate( string $phone ): WP_Error {
	$errors = new WP_Error();
	myotp_test_do_action( 'woocommerce_after_checkout_validation', array( 'billing_phone' => $phone ), $errors );
	return $errors;
}
myotp_test_configure();
check( 'checkout: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
MyOTP_PV_Session::set_verified( '14155551234' );
check( 'checkout: different billing phone blocked', array( 'myotp_pv_mismatch' ), myotp_test_checkout_validate( '+1 415 555 0000' )->get_error_codes() );
check( 'checkout: empty billing phone blocked', array( 'myotp_pv_mismatch' ), myotp_test_checkout_validate( '' )->get_error_codes() );
check( 'checkout: matching billing phone passes', array(), myotp_test_checkout_validate( '+1 (415) 555-1234' )->get_error_codes() );
// Two parallel checkouts both passed validation on the same proof.
check( 'checkout: second parallel validation also passes (proof not yet claimed)', array(), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
$order_a = new MyOTP_Fake_Order( 101 );
$order_b = new MyOTP_Fake_Order( 102 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_a );
check( 'checkout: first order claims and is stamped', '14155551234', $order_a->meta['_myotp_verified_phone'] );
check( 'checkout: first order saved', 1, $order_a->saved );
check( 'checkout: first order has no note', array(), $order_a->notes );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_b );
check( 'checkout: second order not stamped', false, isset( $order_b->meta['_myotp_verified_phone'] ) );
check( 'checkout: second order gets a note', 1, count( $order_b->notes ) );
check( 'checkout: record consumed by first order', 'consumed:order:101', json_decode( MyOTP_PV_Store::$instance->get( 'verified_c_' . str_repeat( 'a', 32 ) ), true )['state'] );
check( 'checkout: third order needs a new verification', array( 'myotp_pv_unverified' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in']                                       = true;
check( 'checkout: guests-only skips logged-in customers', array(), myotp_test_checkout_validate( '' )->get_error_codes() );

// Privacy policy text through admin_init.
myotp_test_configure();
myotp_test_do_action( 'admin_init' );
check( 'privacy: content registered', 'MyOTP Phone Verification', $GLOBALS['myotp_test']['privacy'][0] );
check( 'privacy: links policy', true, false !== strpos( $GLOBALS['myotp_test']['privacy'][1], 'https://myotp.app/privacy-policy/' ) );
check( 'privacy: links terms', true, false !== strpos( $GLOBALS['myotp_test']['privacy'][1], 'https://myotp.app/term-condition/' ) );

echo "\n$count checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
