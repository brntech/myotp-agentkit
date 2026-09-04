<?php
/**
 * Plain PHP tests. Part 1 covers the pure helpers in includes/functions.php
 * over an in-memory store. Part 2 loads the plugin against the fakes in
 * wp-stubs.php, boots it through plugins_loaded, asserts every hook the
 * sources register (derived by scanning the sources here, so a new hook
 * cannot be missed), and drives every flow through those registered
 * callables, including the interleavings the reviews asked for.
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
check( 'array input is empty', '', myotp_pv_normalize_phone( array( '1' ) ) );
check( 'valid 11 digits', true, myotp_pv_is_valid_phone( '14155551234' ) );
check( 'too short', false, myotp_pv_is_valid_phone( '123456' ) );
check( 'leading zero invalid for API', false, myotp_pv_is_valid_phone( '0044123456' ) );
check( 'otp 6 digits', true, myotp_pv_is_valid_otp( '482917' ) );
check( 'otp 2 digits', false, myotp_pv_is_valid_otp( '12' ) );
check( 'otp letters', false, myotp_pv_is_valid_otp( '12a4' ) );

// Atomic counter.
$now = 1_000_000;
$s   = new MyOTP_Mem_Store();
for ( $i = 1; $i <= 5; $i++ ) {
	$r = myotp_pv_take_slot( $s, 'k', $now + $i, 5, 600 );
	check( "take $i count", $i, $r['count'] );
}
$r = myotp_pv_take_slot( $s, 'k', $now + 6, 5, 600 );
check( 'sixth take denied', false, $r['allowed'] );
check( 'sixth retry_after to window end', 595, $r['retry_after'] );
$r = myotp_pv_take_slot( $s, 'k', $now + 601, 5, 600 );
check( 'window rolls over at start+window', 1, $r['count'] );
$s->rows['k'] = 'garbage';
check( 'garbage row replaced', 1, myotp_pv_take_slot( $s, 'k', $now, 5, 600 )['count'] );

// Race: a second taker lands between our read and our cas.
$s = new MyOTP_Mem_Store();
myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
$s->before_cas = function ( $store ) use ( $now ) {
	myotp_pv_take_slot( $store, 'k', $now, 5, 600 );
};
$r = myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'race: count includes the interleaved take', 3, $r['count'] );
$s = new MyOTP_Mem_Store();
for ( $i = 0; $i < 4; $i++ ) {
	myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
}
$s->before_cas = function ( $store ) use ( $now ) {
	myotp_pv_take_slot( $store, 'k', $now, 5, 600 );
};
check( 'race at cap: denied', false, myotp_pv_take_slot( $s, 'k', $now, 5, 600 )['allowed'] );
check( 'race at cap: count is 5 not 6', 5, json_decode( $s->rows['k'], true )['c'] );

// Release.
$s = new MyOTP_Mem_Store();
myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
myotp_pv_take_slot( $s, 'k', $now, 5, 600 );
check( 'release decrements', true, myotp_pv_release_slot( $s, 'k', $now, 600 ) && 1 === json_decode( $s->rows['k'], true )['c'] );
myotp_pv_release_slot( $s, 'k', $now, 600 );
check( 'release floors at zero', false, myotp_pv_release_slot( $s, 'k', $now, 600 ) );
check( 'release on expired window', false, myotp_pv_release_slot( $s, 'k', $now + 601, 600 ) );

// Dimensions with their own windows.
$s    = new MyOTP_Mem_Store();
$dims = array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p', 3 ), array( 'site', 100, 3600 ) );
for ( $i = 0; $i < 3; $i++ ) {
	$r = myotp_pv_take_send_slots( $s, $dims, $now, 600 );
}
check( 'dims taken keys carry windows', array( array( 'v', 600 ), array( 'ip', 600 ), array( 'p', 600 ), array( 'site', 3600 ) ), $r['taken'] );
$r = myotp_pv_take_send_slots( $s, $dims, $now, 600 );
check( 'phone cap 3 denies fourth', 'p', $r['denied'] );
check( 'denied request released visitor slot', 3, json_decode( $s->rows['v'], true )['c'] );
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p2', 3 ), array( 'site', 4, 3600 ) ), $now + 700, 600 );
check( 'after 10 min: visitor window rolled', 1, json_decode( $s->rows['v'], true )['c'] );
check( 'after 10 min: site window still counting', 4, json_decode( $s->rows['site'], true )['c'] );
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p3', 3 ), array( 'site', 4, 3600 ) ), $now + 701, 600 );
check( 'site is the denied dimension', 'site', $r['denied'] );
check( 'site denial retry_after runs to the hour', 3600 - 701, $r['retry_after'] );

// Conditional delete contract.
$s = new MyOTP_Mem_Store();
$s->set( 'x', 'old', 60 );
check( 'delete with stale expected value refused', false, $s->delete( 'x', 'other' ) );
check( 'delete with matching expected value', true, $s->delete( 'x', 'old' ) );

// Guarded install.
$s = new MyOTP_Mem_Store();
check( 'install into empty', true, null !== myotp_pv_install( $s, 'k', null, array( 'a' => 1 ), 60 ) );
check( 'install into empty when row appeared loses', null, myotp_pv_install( $s, 'k', null, array( 'a' => 2 ), 60 ) );
$raw = $s->get( 'k' );
check( 'install by cas wins', true, null !== myotp_pv_install( $s, 'k', $raw, array( 'a' => 3 ), 60 ) );
check( 'install by stale cas loses', null, myotp_pv_install( $s, 'k', $raw, array( 'a' => 4 ), 60 ) );

// Attempt reservation: no delete on exhaustion, raw returned, exp honoured.
$s = new MyOTP_Mem_Store();
check( 'no pending: not ok', false, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['ok'] );
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'm1', 'attempts' => 0, 'exp' => $now + 300 ) ), 300 );
for ( $i = 1; $i <= 5; $i++ ) {
	$r = myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
	check( "attempt $i counted", $i, $r['attempts'] );
}
check( 'reserve returns the raw it wrote', $s->get( 'pend' ), $r['raw'] );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
check( 'sixth attempt locked', true, $r['locked'] );
check( 'exhausted record is not deleted by reserve', true, null !== $s->get( 'pend' ) );
check( 'ttl_left from exp', 300, myotp_pv_ttl_left( array( 'exp' => $now + 300 ), $now ) );
check( 'ttl_left floors at 1', 1, myotp_pv_ttl_left( array( 'exp' => $now - 5 ), $now ) );
$s->set( 'pend', 'garbage', 60 );
myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
check( 'garbage delete was guarded', array( 'pend', 'garbage' ), end( $s->deletes ) );

// Race on attempts.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'm1', 'attempts' => 3, 'exp' => $now + 300 ) ), 300 );
$s->before_cas = function ( $store ) use ( $now ) {
	myotp_pv_reserve_attempt( $store, 'pend', 5, $now );
};
check( 'attempt race: retried and counted', 5, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['attempts'] );
check( 'attempt race: next is locked', true, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['locked'] );

// Attempt release.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '1', 'message_id' => 'm', 'attempts' => 2, 'exp' => time() + 60 ) ), 60 );
check( 'release attempt decrements', true, myotp_pv_release_attempt( $s, 'pend' ) && 1 === json_decode( $s->get( 'pend' ), true )['attempts'] );
myotp_pv_release_attempt( $s, 'pend' );
check( 'release attempt floors at zero', false, myotp_pv_release_attempt( $s, 'pend' ) );

// Phone lock.
$s = new MyOTP_Mem_Store();
check( 'no lock', 0, myotp_pv_lock_remaining( $s, 'lock:1', $now ) );
check( 'lock created', true, myotp_pv_lock_phone( $s, 'lock:1', $now, 900 ) );
check( 'lock remaining', 900, myotp_pv_lock_remaining( $s, 'lock:1', $now ) );
check( 'lock remaining later', 300, myotp_pv_lock_remaining( $s, 'lock:1', $now + 600 ) );
check( 'second lock does not shorten the first', false, myotp_pv_lock_phone( $s, 'lock:1', $now + 600, 900 ) );
check( 'lock remaining unchanged', 300, myotp_pv_lock_remaining( $s, 'lock:1', $now + 600 ) );
check( 'lock gone after until', 0, myotp_pv_lock_remaining( $s, 'lock:1', $now + 900 ) );

// Verified record: claim then consume, bound to phone and request id.
check( 'verified fresh', '14155551234', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 10, 'state' => 'verified' ), $now, 1800 ) );
check( 'verified at exactly ttl expired', '', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 1800, 'state' => 'verified' ), $now, 1800 ) );
check( 'verified claiming reads as unverified', '', myotp_pv_verified_phone_from( array( 'phone' => '1', 'at' => $now - 5, 'state' => 'claiming:1:r' ), $now, 1800 ) );
$s = new MyOTP_Mem_Store();
check( 'claim on missing', '', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now ) );
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
check( 'first claim wins', '14155551234', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now ) );
check( 'record marked claiming', 'claiming:14155551234:rA', json_decode( $s->get( 'ver' ), true )['state'] );
check( 'second claim loses', '', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rB', $now ) );
check( 'consume with wrong request id loses', '', myotp_pv_consume_claim( $s, 'ver', '14155551234', 'rB', 'order:1', $now ) );
check( 'consume with wrong phone loses', '', myotp_pv_consume_claim( $s, 'ver', '14155559999', 'rA', 'order:1', $now ) );
check( 'consume with the claim wins', '14155551234', myotp_pv_consume_claim( $s, 'ver', '14155551234', 'rA', 'order:1', $now ) );
check( 'record marked consumed', 'consumed:order:1', json_decode( $s->get( 'ver' ), true )['state'] );
check( 'consume twice loses', '', myotp_pv_consume_claim( $s, 'ver', '14155551234', 'rA', 'order:2', $now ) );
// Claim race: two requests both read "verified"; only one wins.
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
$won           = array();
$s->before_cas = function ( $store ) use ( $now, &$won ) {
	$won[] = myotp_pv_claim_verified( $store, 'ver', '14155551234', 'rB', $now );
};
$won[] = myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now );
check( 'claim race: exactly one winner', 1, count( array_filter( $won ) ) );
check( 'claim race: the interleaved request won', 'claiming:14155551234:rB', json_decode( $s->get( 'ver' ), true )['state'] );
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 1801, 'state' => 'verified' ) ), 1800 );
check( 'claim on expired', '', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now ) );

check( 'send body ok', true, myotp_pv_is_send_body( array( 'message_id' => 'abc' ) ) );
check( 'send body no id', false, myotp_pv_is_send_body( array( 'status' => 'accepted' ) ) );

// Option sanitisation.
$key = 'abcdefghijklmnopqrstuvwxyz012345';
check( 'empty input yields defaults', myotp_pv_default_options(), myotp_pv_sanitize_options( array(), array() ) );
check( 'mask keeps current key', $key, myotp_pv_sanitize_options( array( 'api_key' => MYOTP_PV_KEY_MASK ), array( 'api_key' => $key ) )['api_key'] );
check( 'unknown channel falls back to sms', 'sms', myotp_pv_sanitize_options( array( 'channel' => 'pigeon' ), array( 'channel' => 'telegram' ) )['channel'] );
check( 'site cap 250', 250, myotp_pv_sanitize_options( array( 'site_hourly_cap' => '250' ), array() )['site_hourly_cap'] );
check( 'site cap 0 keeps current', 100, myotp_pv_sanitize_options( array( 'site_hourly_cap' => '0' ), array() )['site_hourly_cap'] );
check( 'missing checkbox means off', 0, myotp_pv_sanitize_options( array( 'otp_length' => 6 ), array( 'wc_enabled' => 1 ) )['wc_enabled'] );
check( 'envelope message', 'Insufficient balance', myotp_pv_error_message( array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ), 402 ) );

// ---------------------------------------------------------------- Part 2: boot through hooks and drive them.

myotp_test_reset();
$GLOBALS['myotp_test']['options']['myotp_pv_options'] = array_merge(
	myotp_pv_default_options(),
	array( 'api_key' => $key, 'register_enabled' => 1, 'wc_enabled' => 1 )
);
myotp_test_do_action( 'plugins_loaded' );

// Every hook the sources register must be in the registry after boot.
$src_dir  = __DIR__ . '/../myotp-phone-verification';
$expected = array();
$dynamic  = array();
foreach ( array_merge( glob( $src_dir . '/*.php' ), glob( $src_dir . '/includes/*.php' ) ) as $f ) {
	$code = file_get_contents( $f );
	preg_match_all( "/add_(?:action|filter)\\(\\s*'([^']+)'/", $code, $m );
	foreach ( $m[1] as $hook ) {
		$expected[ $hook ] = true;
	}
	preg_match_all( "/add_(?:action|filter)\\(\\s*'([^']+)'\\s*\\./", $code, $m );
	foreach ( $m[1] as $prefix ) {
		$dynamic[ $prefix ] = true;
		unset( $expected[ $prefix ] );
	}
	preg_match_all( "/add_shortcode\\(\\s*'([^']+)'/", $code, $m );
	foreach ( $m[1] as $tag ) {
		$expected[ 'shortcode:' . $tag ] = true;
	}
}
check( 'source scan found hooks', true, count( $expected ) > 20 );
foreach ( array_keys( $expected ) as $hook ) {
	check( "hook registered: $hook", true, myotp_test_has_hook( $hook ) );
}
foreach ( array_keys( $dynamic ) as $prefix ) {
	$hit = false;
	foreach ( array_keys( $GLOBALS['myotp_test']['hooks'] ) as $name ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			$hit = true;
		}
	}
	check( "dynamic hook registered: {$prefix}*", true, $hit );
}
foreach ( array(
	'wp_ajax_nopriv_myotp_pv_send',
	'wp_ajax_myotp_pv_send',
	'wp_ajax_nopriv_myotp_pv_verify',
	'wp_ajax_myotp_pv_verify',
	'wp_ajax_myotp_pv_test',
	'before_woocommerce_init',
	'admin_enqueue_scripts',
	'show_user_profile',
	'edit_user_profile',
	'woocommerce_after_checkout_validation',
	'woocommerce_checkout_order_created',
	'registration_errors',
	'register_new_user',
	'wp_enqueue_scripts',
	'login_enqueue_scripts',
	'admin_init',
	'plugins_loaded',
	'init',
	'myotp_pv_sweep',
) as $must ) {
	check( "scan covers $must", true, isset( $expected[ $must ] ) );
}
check( 'scan covers plugin_action_links_*', true, isset( $dynamic['plugin_action_links_'] ) );
check( 'hook: wp_ajax_myotp_pv_test -> admin_test', true, myotp_test_has_hook( 'wp_ajax_myotp_pv_test', array( 'MyOTP_PV_Ajax', 'admin_test' ) ) );
check( 'hook: woocommerce_checkout_order_created -> consume', true, myotp_test_has_hook( 'woocommerce_checkout_order_created', array( 'MyOTP_PV_WooCommerce', 'consume' ) ) );
check( 'hook: user_register is not used', false, myotp_test_has_hook( 'user_register' ) );
check( 'hook: no nopriv admin test', false, myotp_test_has_hook( 'wp_ajax_nopriv_myotp_pv_test' ) );

// Cron scheduling and continuation.
myotp_test_do_action( 'init' );
check( 'cron: sweep scheduled daily', 'daily', $GLOBALS['myotp_test']['cron']['myotp_pv_sweep'][1] );
myotp_test_do_action( 'deactivate' );
check( 'cron: deactivation clears sweep', false, wp_next_scheduled( 'myotp_pv_sweep' ) );
myotp_test_do_action( 'activate' );
check( 'cron: activation schedules sweep', true, false !== wp_next_scheduled( 'myotp_pv_sweep' ) );
myotp_test_do_action( 'myotp_pv_sweep' );
check( 'cron: sweep with no full batch schedules nothing', 0, count( $GLOBALS['myotp_test']['single'] ) );
MyOTP_PV_Store::$instance->sweep_full = true;
myotp_test_do_action( 'myotp_pv_sweep' );
check( 'cron: full batch schedules a continuation', 'myotp_pv_sweep', $GLOBALS['myotp_test']['single'][0][1] );
check( 'cron: continuation is about five minutes out', true, abs( $GLOBALS['myotp_test']['single'][0][0] - ( time() + 300 ) ) < 5 );

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
	$raw = MyOTP_PV_Store::$instance->get( 'pending_c_' . $_COOKIE['myotp_pv_sid'] );
	return null === $raw ? null : json_decode( $raw, true );
}
function myotp_test_counter( string $key ): int {
	$raw = MyOTP_PV_Store::$instance->get( $key );
	return null === $raw ? 0 : (int) json_decode( $raw, true )['c'];
}
function myotp_test_vrec(): ?array {
	$raw = MyOTP_PV_Store::$instance->get( 'verified_c_' . str_repeat( 'a', 32 ) );
	return null === $raw ? null : json_decode( $raw, true );
}
function myotp_test_wrong( int $n ): void {
	for ( $i = 0; $i < $n; $i++ ) {
		myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
		myotp_test_verify( '000000' );
	}
}

// Nonce and configuration guards.
myotp_test_configure();
$GLOBALS['myotp_test']['nonce_ok'] = false;
check( 'send: bad nonce refused 403', 403, myotp_test_send( '14155551234' )->status );
check( 'send: bad nonce made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['api_key'] = '';
check( 'send: no key configured is an error', false, myotp_test_send( '14155551234' )->success );
myotp_test_configure();
check( 'send: bad phone 400', 400, myotp_test_send( '+0 12' )->status );

// Success installs a pending challenge with message id and absolute expiry equal to the validity.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = 7200;
myotp_test_http( 200, array( 'message_id' => 'msg-1', 'status' => 'accepted' ) );
$r = myotp_test_send( '+1 (415) 555-1234' );
check( 'send: success', true, $r->success );
$call = $GLOBALS['myotp_test']['http_log'][0];
check( 'send: X-API-Key header', 'abcdefghijklmnopqrstuvwxyz012345', $call['args']['headers']['X-API-Key'] );
check( 'send: force_send false', false, json_decode( $call['args']['body'], true )['force_send'] );
check( 'send: pending stored with message id', 'msg-1', myotp_test_pending()['message_id'] );
check( 'send: pending expiry equals validity', true, abs( myotp_test_pending()['exp'] - ( time() + 7200 ) ) < 5 );
check( 'send: site counter taken', 1, myotp_test_counter( 'send_site' ) );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = 86400;
check( 'pending ttl capped at a day', 86400, MyOTP_PV_Session::pending_ttl() );

// Provider answers and slot accounting.
myotp_test_configure();
myotp_test_http( 200, array( 'status' => 'accepted' ) );
check( 'send: 200 without message_id is an error', false, myotp_test_send( '14155551234' )->success );
myotp_test_configure();
myotp_test_http( 402, array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ) );
check( 'send: 402 surfaced', 'Insufficient balance', myotp_test_send( '14155551234' )->data['message'] );
check( 'send: 4xx keeps the site slot', 1, myotp_test_counter( 'send_site' ) );
myotp_test_configure();
myotp_test_http( 503, array( 'error' => array( 'http_code' => 503, 'message' => 'Down' ) ) );
myotp_test_send( '14155551234' );
check( 'send: 5xx refunds the site slot', 0, myotp_test_counter( 'send_site' ) );
check( 'send: 5xx keeps the phone slot', 1, myotp_test_counter( 'send_p_14155551234' ) );
myotp_test_configure();
myotp_test_http( 'wp_error', null );
myotp_test_send( '14155551234' );
check( 'send: transport error refunded phone slot', 0, myotp_test_counter( 'send_p_14155551234' ) );
check( 'send: transport error refunded site slot', 0, myotp_test_counter( 'send_site' ) );

// 409 semantics: own challenge kept with attempts, foreign challenge refused, site slot refunded.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-2' ) );
myotp_test_send( '14155551234' );
myotp_test_wrong( 3 );
check( '409: three attempts used before resend', 3, myotp_test_pending()['attempts'] );
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
$r = myotp_test_send( '14155551234' );
check( '409: own challenge soft success', true, $r->success );
check( '409: attempts kept', 3, myotp_test_pending()['attempts'] );
check( '409: message id kept', 'msg-2', myotp_test_pending()['message_id'] );
check( '409: site slot refunded', 1, myotp_test_counter( 'send_site' ) );
check( '409: phone slot kept', 2, myotp_test_counter( 'send_p_14155551234' ) );
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
$r = myotp_test_send( '14155551234' );
check( '409 foreign challenge: refused', 409, $r->status );
check( '409 foreign challenge: no pending created', null, myotp_test_pending() );
check( '409 foreign challenge: verify has nothing', 'Request a code first.', myotp_test_verify( '123456' )->data['message'] );

// Lockout: 5 wrong codes lock the phone for every visitor; send and verify refuse; 409 cannot revive.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-3' ) );
myotp_test_send( '14155551234' );
myotp_test_wrong( 4 );
myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
$r = myotp_test_verify( '000000' );
check( 'lock: fifth wrong answer surfaced', 'Invalid OTP', $r->data['message'] );
check( 'lock: remaining zero', 0, $r->data['remaining'] );
check( 'lock: pending dropped', null, myotp_test_pending() );
check( 'lock: lock record written', true, MyOTP_PV_Session::lock_remaining( '14155551234' ) > 890 );
$r = myotp_test_verify( '000000' );
check( 'lock: verify refused', 400, $r->status );
$r = myotp_test_send( '14155551234' );
check( 'lock: send refused 429', 429, $r->status );
check( 'lock: send message names minutes', 'Too many wrong codes for this number. Try again in 15 minutes.', $r->data['message'] );
check( 'lock: no HTTP call while locked', 6, count( $GLOBALS['myotp_test']['http_log'] ) );
$_COOKIE['myotp_pv_sid'] = str_repeat( 'f', 32 );
$_SERVER['REMOTE_ADDR']  = '198.51.100.7';
check( 'lock: another visitor and ip refused for the same phone', 429, myotp_test_send( '14155551234' )->status );
myotp_test_http( 200, array( 'message_id' => 'msg-4' ) );
check( 'lock: another phone still allowed', true, myotp_test_send( '14155550000' )->success );
// Lock expiry: shrink the lock and confirm a new send works and starts at zero attempts.
MyOTP_PV_Store::$instance->rows['lock:14155551234'] = myotp_pv_json( array( 'at' => time() - 1000, 'until' => time() - 1 ) );
$_COOKIE['myotp_pv_sid']                              = str_repeat( 'a', 32 );
myotp_test_http( 200, array( 'message_id' => 'msg-5' ) );
check( 'lock: expired lock allows a new send', true, myotp_test_send( '14155551234' )->success );
check( 'lock: new challenge starts at zero attempts', 0, myotp_test_pending()['attempts'] );
check( 'lock: new challenge has the new message id', 'msg-5', myotp_test_pending()['message_id'] );

// Interleaving: lock record blocks a 409 resend that would otherwise revive the challenge.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-6' ) );
myotp_test_send( '14155551234' );
myotp_test_wrong( 5 );
check( 'lock+409: locked', true, MyOTP_PV_Session::lock_remaining( '14155551234' ) > 0 );
$r = myotp_test_send( '14155551234' );
check( 'lock+409: resend refused before the provider', 429, $r->status );
check( 'lock+409: provider not called', 6, count( $GLOBALS['myotp_test']['http_log'] ) );
check( 'lock+409: no pending revived', null, myotp_test_pending() );

// Rate limits across cookies, IPs and the site cap.
myotp_test_configure();
for ( $i = 0; $i < 3; $i++ ) {
	myotp_test_http( 200, array( 'message_id' => "m$i" ) );
	myotp_test_send( '14155551234' );
}
check( 'limit: 4th send to same number refused', 429, myotp_test_send( '14155551234' )->status );
myotp_test_http( 200, array( 'message_id' => 'm4' ) );
myotp_test_send( '14155550001' );
myotp_test_http( 200, array( 'message_id' => 'm5' ) );
myotp_test_send( '14155550002' );
check( 'limit: 6th send by one visitor refused', false, myotp_test_send( '14155550003' )->success );
for ( $i = 0; $i < 5; $i++ ) {
	$_COOKIE['myotp_pv_sid'] = str_repeat( 'b', 32 );
	myotp_test_http( 200, array( 'message_id' => "n$i" ) );
	myotp_test_send( '1415555100' . $i );
}
$_COOKIE['myotp_pv_sid'] = str_repeat( 'c', 32 );
check( 'limit: 11th send from one IP refused despite new cookie', false, myotp_test_send( '14155552000' )->success );
check( 'limit: ip refusals made no HTTP call', 10, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['site_hourly_cap'] = 4;
for ( $i = 0; $i < 4; $i++ ) {
	$_SERVER['REMOTE_ADDR']  = '198.51.100.' . ( 10 + $i );
	$_COOKIE['myotp_pv_sid'] = str_repeat( (string) $i, 32 );
	myotp_test_http( 200, array( 'message_id' => "s$i" ) );
	myotp_test_send( '4477000000' . $i );
}
$_SERVER['REMOTE_ADDR']  = '198.51.100.99';
$_COOKIE['myotp_pv_sid'] = str_repeat( '9', 32 );
check( 'site cap: 5th send from yet another ip refused', 429, myotp_test_send( '447700009999' )->status );
add_filter( 'myotp_pv_site_hourly_cap', function ( $cap ) { return $cap + 1; } );
myotp_test_http( 200, array( 'message_id' => 's5' ) );
check( 'site cap: filter raises the ceiling', true, myotp_test_send( '447700009999' )->success );
$GLOBALS['myotp_test']['hooks']['myotp_pv_site_hourly_cap'] = array();
add_filter( 'myotp_pv_client_ip', function ( $ip ) { return '10.0.0.1'; } );
check( 'ip filter applied', '10.0.0.1', MyOTP_PV_Session::client_ip() );
$GLOBALS['myotp_test']['hooks']['myotp_pv_client_ip'] = array();

// Verify: order of checks, attempt accounting, message id on the wire.
myotp_test_configure();
check( 'verify: nothing pending', 'Request a code first.', myotp_test_verify( '123456' )->data['message'] );
myotp_test_http( 200, array( 'message_id' => 'msg-9' ) );
myotp_test_send( '14155551234' );
check( 'verify: bad code shape 400', 400, myotp_test_verify( '12' )->status );
check( 'verify: changed number refused', 400, myotp_test_verify( '123456', '14155559999' )->status );
check( 'verify: mismatch did not consume an attempt', 0, myotp_test_pending()['attempts'] );
myotp_test_http( 'wp_error', null );
myotp_test_verify( '123456' );
check( 'verify: transport error did not consume an attempt', 0, myotp_test_pending()['attempts'] );
myotp_test_http( 500, '<html>oops</html>' );
myotp_test_verify( '123456' );
check( 'verify: 500 consumed an attempt', 1, myotp_test_pending()['attempts'] );
check( 'verify: message_id on the wire', 'msg-9', json_decode( $GLOBALS['myotp_test']['http_log'][1]['args']['body'], true )['message_id'] );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify: success', true, $r->success );
check( 'verify: record state verified', 'verified', myotp_test_vrec()['state'] );
check( 'verify: pending cleared after success', null, myotp_test_pending() );
check( 'verify: session reports verified', '14155551234', MyOTP_PV_Session::verified_phone() );
// Expired status drops the pending record (guarded).
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-11' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'expired', 'message' => 'OTP expired' ) );
check( 'verify: expired surfaced', 'OTP expired', myotp_test_verify( '111111' )->data['message'] );
check( 'verify: expired code dropped', null, myotp_test_pending() );

// Interleaving: verify A in flight while send B installs a new challenge; A must not delete B's record.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-A' ) );
myotp_test_send( '14155551234' );
$GLOBALS['myotp_test']['http_before'] = function () {
	// Runs during A's provider call: B requests a fresh code for the same visitor.
	// B's canned answer must be served before A's, so it goes to the front of the queue.
	array_unshift( $GLOBALS['myotp_test']['http_queue'], array( 200, array( 'message_id' => 'msg-B' ) ) );
	try {
		myotp_test_send( '14155551234' );
	} catch ( MyOTP_Test_Exit $e ) {
		// unreachable: myotp_test_send returns the exit.
	}
	$_POST = array( 'otp' => '482917', 'phone' => '', 'nonce' => 'x' );
};
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify race: A still succeeds', true, $r->success );
check( 'verify race: B pending survives A cleanup', 'msg-B', myotp_test_pending()['message_id'] );
check( 'verify race: B pending attempts untouched', 0, myotp_test_pending()['attempts'] );
check( 'verify race: A proof installed', 'verified', myotp_test_vrec()['state'] );
// And the reverse: send B in flight while A's verify writes proof; B's send must not delete A's proof.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-A2' ) );
myotp_test_send( '14155551234' );
$GLOBALS['myotp_test']['http_before'] = function () {
	array_unshift( $GLOBALS['myotp_test']['http_queue'], array( 200, array( 'status' => 'success', 'message' => 'OK' ) ) );
	myotp_test_verify( '482917' );
	$_POST = array( 'phone' => '14155551234', 'nonce' => 'x' );
};
myotp_test_http( 200, array( 'message_id' => 'msg-B2' ) );
$r = myotp_test_send( '14155551234' );
check( 'send race: B send refused (pending changed under it)', 409, $r->status );
check( 'send race: A proof kept', 'verified', myotp_test_vrec()['state'] );

// Admin test through its hook.
myotp_test_configure();
$_POST = array( 'phone' => '14155551234', 'nonce' => 'x' );
check( 'admin test: no capability 403', 403, myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->status );
$GLOBALS['myotp_test']['can_manage'] = true;
myotp_test_http( 200, array( 'message_id' => 'msg-t', 'status' => 'accepted', 'cost' => 1 ) );
check( 'admin test: success', 'Sent to 14155551234. Message ID msg-t.', myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->data['message'] );

// Registration: claim in registration_errors, consume in register_new_user.
function myotp_test_register_validate( ?WP_Error $errors = null ): WP_Error {
	return apply_filters( 'registration_errors', $errors ?? new WP_Error(), 'bob', 'bob@example.test' );
}
myotp_test_configure();
check( 'register: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_register_validate()->get_error_codes() );
myotp_test_do_action( 'register_new_user', 42 );
check( 'register: save without a claim stamps nothing', '', get_user_meta( 42, 'myotp_verified_phone', true ) );
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '';
check( 'register: empty submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), myotp_test_register_validate()->get_error_codes() );
$_POST['myotp_pv_phone'] = '+1 415 555 9999';
check( 'register: different submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), myotp_test_register_validate()->get_error_codes() );
check( 'register: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
$_POST['myotp_pv_phone'] = '+1 (415) 555-1234';
$errors                  = new WP_Error();
$errors->add( 'username_exists', 'taken' );
$errors = myotp_test_register_validate( $errors );
check( 'register: other error leaves ours out', array( 'username_exists' ), $errors->get_error_codes() );
check( 'register: other error means no claim', 'verified', myotp_test_vrec()['state'] );
myotp_test_do_action( 'register_new_user', 44 );
check( 'register: no stamp without a claim', '', get_user_meta( 44, 'myotp_verified_phone', true ) );
check( 'register: matching phone passes', array(), myotp_test_register_validate()->get_error_codes() );
check( 'register: validation claimed the proof', 'claiming:14155551234:' . MyOTP_PV_Session::$request_id, myotp_test_vrec()['state'] );
check( 'register: claimed proof reads as unverified elsewhere', '', MyOTP_PV_Session::verified_phone() );
$saved_post              = $_POST;
$_POST['myotp_pv_phone'] = '14155550000';
myotp_test_do_action( 'register_new_user', 45 );
check( 'register: other posted phone not stamped', '', get_user_meta( 45, 'myotp_verified_phone', true ) );
check( 'register: claim still open', 'claiming:14155551234:' . MyOTP_PV_Session::$request_id, myotp_test_vrec()['state'] );
$_POST = $saved_post;
myotp_test_do_action( 'register_new_user', 46 );
check( 'register: consume stamps meta', '14155551234', get_user_meta( 46, 'myotp_verified_phone', true ) );
check( 'register: record consumed by user', 'consumed:user:46', myotp_test_vrec()['state'] );
myotp_test_do_action( 'register_new_user', 47 );
check( 'register: claim is single use', '', get_user_meta( 47, 'myotp_verified_phone', true ) );
// Two registrations sharing one proof: the second fails validation.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '14155551234';
check( 'register x2: first passes', array(), myotp_test_register_validate()->get_error_codes() );
MyOTP_PV_Session::$request_id = 'rid-second';
check( 'register x2: second refused', array( 'myotp_pv_claimed' ), myotp_test_register_validate()->get_error_codes() );

// Checkout: claim at validation, consume at order creation, exactly one order per proof.
function myotp_test_checkout_validate( string $phone ): WP_Error {
	$errors = new WP_Error();
	myotp_test_do_action( 'woocommerce_after_checkout_validation', array( 'billing_phone' => $phone ), $errors );
	return $errors;
}
myotp_test_configure();
check( 'checkout: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
MyOTP_PV_Session::set_verified( '14155551234', null );
check( 'checkout: different billing phone blocked', array( 'myotp_pv_mismatch' ), myotp_test_checkout_validate( '+1 415 555 0000' )->get_error_codes() );
check( 'checkout: empty billing phone blocked', array( 'myotp_pv_mismatch' ), myotp_test_checkout_validate( '' )->get_error_codes() );
check( 'checkout: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
// Request A validates and claims.
MyOTP_PV_Session::$request_id = 'rid-A';
check( 'checkout A: matching billing phone passes', array(), myotp_test_checkout_validate( '+1 (415) 555-1234' )->get_error_codes() );
check( 'checkout A: proof claimed', 'claiming:14155551234:rid-A', myotp_test_vrec()['state'] );
// Request B, sharing the proof, fails validation: no second order can be created.
MyOTP_PV_Session::$request_id = 'rid-B';
check( 'checkout B: refused at validation', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
// A's order is created and consumes the claim. B's failed validation reset the
// per-request static in this shared process, so put A's request context back.
MyOTP_PV_Session::$request_id = 'rid-A';
$prop                         = new ReflectionProperty( 'MyOTP_PV_WooCommerce', 'claimed' );
$prop->setAccessible( true );
$prop->setValue( null, '14155551234' );
$order_a = new MyOTP_Fake_Order( 101 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_a );
check( 'checkout A: order stamped', '14155551234', $order_a->meta['_myotp_verified_phone'] );
check( 'checkout A: order saved', 1, $order_a->saved );
check( 'checkout A: record consumed by order', 'consumed:order:101', myotp_test_vrec()['state'] );
// A second order_created in the same request (or a stale one) cannot consume again.
$prop->setValue( null, '14155551234' );
$order_b = new MyOTP_Fake_Order( 102 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_b );
check( 'checkout stale: not stamped', false, isset( $order_b->meta['_myotp_verified_phone'] ) );
check( 'checkout stale: gets a note', 1, count( $order_b->notes ) );
check( 'checkout: third checkout on a consumed proof is told to verify again', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
// Interleaving at validation: both read "verified", both CAS, exactly one passes.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$passed                                     = array();
MyOTP_PV_Store::$instance->before_cas       = function ( $store ) use ( &$passed ) {
	$rid                          = MyOTP_PV_Session::$request_id;
	MyOTP_PV_Session::$request_id = 'rid-other';
	$passed['other']              = myotp_test_checkout_validate( '+14155551234' )->get_error_codes();
	MyOTP_PV_Session::$request_id = $rid;
};
MyOTP_PV_Session::$request_id = 'rid-me';
$passed['me']                 = myotp_test_checkout_validate( '+14155551234' )->get_error_codes();
check( 'checkout race: interleaved request passed', array(), $passed['other'] );
check( 'checkout race: the other request refused', array( 'myotp_pv_claimed' ), $passed['me'] );
check( 'checkout race: claim belongs to the winner', 'claiming:14155551234:rid-other', myotp_test_vrec()['state'] );
// Guests-only.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in']                                       = true;
check( 'checkout: guests-only skips logged-in customers', array(), myotp_test_checkout_validate( '' )->get_error_codes() );

// No unconditional writes on pending or verified rows: every delete carries an expected value.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-g' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'success' ) );
myotp_test_verify( '482917' );
myotp_test_http( 200, array( 'message_id' => 'msg-g2' ) );
myotp_test_send( '14155551234' );
$unguarded = array();
foreach ( MyOTP_PV_Store::$instance->deletes as $d ) {
	if ( null === $d[1] && ( 0 === strpos( $d[0], 'pending_' ) || 0 === strpos( $d[0], 'verified_' ) ) ) {
		$unguarded[] = $d[0];
	}
}
check( 'guards: no unguarded delete on pending or verified rows', array(), $unguarded );
check( 'guards: pending and verified deletes did happen', true, count( MyOTP_PV_Store::$instance->deletes ) >= 2 );

// Privacy policy text through admin_init.
myotp_test_configure();
myotp_test_do_action( 'admin_init' );
check( 'privacy: content registered', 'MyOTP Phone Verification', $GLOBALS['myotp_test']['privacy'][0] );
check( 'privacy: mentions the lock', true, false !== strpos( $GLOBALS['myotp_test']['privacy'][1], '15-minute lock' ) );

// Plugin header description length.
$header = file_get_contents( $src_dir . '/myotp-phone-verification.php' );
preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', $header, $m );
check( 'header: description under 140 chars', true, strlen( trim( $m[1] ) ) < 140 );

// The .pot covers every source string.
$pot = file_get_contents( $src_dir . '/languages/myotp-phone-verification.pot' );
preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)*)"$/m', $pot, $m );
$pot_ids = array_flip( array_map( function ( $v ) { return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $v ); }, $m[1] ) );
$missing = array();
foreach ( array_merge( glob( $src_dir . '/*.php' ), glob( $src_dir . '/includes/*.php' ) ) as $f ) {
	preg_match_all( "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'myotp-phone-verification'/", file_get_contents( $f ), $mm );
	foreach ( $mm[1] as $raw ) {
		$id = str_replace( "\\'", "'", $raw );
		if ( ! isset( $pot_ids[ $id ] ) ) {
			$missing[] = $id;
		}
	}
}
check( 'pot: every source string present', array(), array_values( array_unique( $missing ) ) );

echo "\n$count checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
