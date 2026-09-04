<?php
/**
 * Plain PHP tests. Part 1 covers the pure helpers in includes/functions.php
 * over an in-memory store. Part 2 loads the whole plugin against the fakes
 * in wp-stubs.php and drives the AJAX handlers, the registration validator
 * and the checkout validator end to end.
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

// All-or-nothing across dimensions.
$s    = new MyOTP_Mem_Store();
$dims = array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p', 3 ) );
for ( $i = 0; $i < 3; $i++ ) {
	$r = myotp_pv_take_send_slots( $s, $dims, $now, 600 );
	check( "dims take $i allowed", true, $r['allowed'] );
}
check( 'dims taken keys', array( 'v', 'ip', 'p' ), $r['taken'] );
$r = myotp_pv_take_send_slots( $s, $dims, $now, 600 );
check( 'phone cap 3 denies fourth', false, $r['allowed'] );
check( 'denied dimension named', 'p', $r['denied'] );
check( 'denied request released visitor slot', 3, json_decode( $s->rows['v'], true )['c'] );
check( 'denied request released ip slot', 3, json_decode( $s->rows['ip'], true )['c'] );
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p2', 3 ) ), $now, 600 );
check( 'other phone still allowed', true, $r['allowed'] );
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p3', 3 ) ), $now, 600 );
$r = myotp_pv_take_send_slots( $s, array( array( 'v', 5 ), array( 'ip', 10 ), array( 'p4', 3 ) ), $now, 600 );
check( 'visitor cap 5 denies', false, $r['allowed'] );
check( 'visitor is the denied dimension', 'v', $r['denied'] );
$r = myotp_pv_take_send_slots( $s, array( array( 'v2', 5 ), array( 'ip', 10 ), array( 'p5', 3 ) ), $now, 600 );
check( 'new visitor, same ip: allowed while ip under cap', true, $r['allowed'] );
for ( $i = 0; $i < 4; $i++ ) {
	$r = myotp_pv_take_send_slots( $s, array( array( 'v2', 5 ), array( 'ip', 10 ), array( 'p' . ( 6 + $i ), 3 ) ), $now, 600 );
}
$r = myotp_pv_take_send_slots( $s, array( array( 'v3', 5 ), array( 'ip', 10 ), array( 'p99', 3 ) ), $now, 600 );
check( 'ip cap 10 denies a fresh visitor (cookie rotation)', false, $r['allowed'] );
check( 'ip is the denied dimension', 'ip', $r['denied'] );
check( 'fresh visitor slot was released', 0, json_decode( $s->rows['v3'], true )['c'] );

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
check( 'pending carries phone', '14155551234', $r['pending']['phone'] );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'sixth attempt locked', true, $r['locked'] );
check( 'sixth attempt not ok', false, $r['ok'] );
check( 'pending discarded on lock', null, $s->get( 'pend' ) );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'after lock: needs new send', false, $r['locked'] );
$s->set( 'pend', 'garbage', 60 );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5 );
check( 'garbage pending dropped', null, $s->get( 'pend' ) );

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

// Verified record expiry.
check( 'verified fresh', '14155551234', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 10 ), $now, 1800 ) );
check( 'verified at exactly ttl expired', '', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 1800 ), $now, 1800 ) );
check( 'verified future timestamp rejected', '', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now + 5 ), $now, 1800 ) );
check( 'verified legacy string rejected', '', myotp_pv_verified_phone_from( '14155551234', $now, 1800 ) );
check( 'verified missing at rejected', '', myotp_pv_verified_phone_from( array( 'phone' => '1' ), $now, 1800 ) );

// Send body shape.
check( 'send body ok', true, myotp_pv_is_send_body( array( 'message_id' => 'abc', 'status' => 'accepted' ) ) );
check( 'send body empty id', false, myotp_pv_is_send_body( array( 'message_id' => '' ) ) );
check( 'send body no id', false, myotp_pv_is_send_body( array( 'status' => 'accepted' ) ) );
check( 'send body not array', false, myotp_pv_is_send_body( 'x' ) );

// Option sanitisation.
$key      = 'abcdefghijklmnopqrstuvwxyz012345';
$defaults = myotp_pv_default_options();
check( 'empty input yields defaults', $defaults, myotp_pv_sanitize_options( array(), array() ) );
check( 'valid key stored', $key, myotp_pv_sanitize_options( array( 'api_key' => $key ), array() )['api_key'] );
check( 'invalid key keeps current', $key, myotp_pv_sanitize_options( array( 'api_key' => 'short' ), array( 'api_key' => $key ) )['api_key'] );
check( 'mask keeps current key', $key, myotp_pv_sanitize_options( array( 'api_key' => MYOTP_PV_KEY_MASK ), array( 'api_key' => $key ) )['api_key'] );
check( 'empty key clears', '', myotp_pv_sanitize_options( array( 'api_key' => '' ), array( 'api_key' => $key ) )['api_key'] );
check( 'channel lowercased', 'whatsapp', myotp_pv_sanitize_options( array( 'channel' => 'WhatsApp' ), array() )['channel'] );
check( 'unknown channel falls back to sms', 'sms', myotp_pv_sanitize_options( array( 'channel' => 'pigeon' ), array( 'channel' => 'telegram' ) )['channel'] );
check( 'length 8 ok', 8, myotp_pv_sanitize_options( array( 'otp_length' => '8' ), array() )['otp_length'] );
check( 'length 3 falls to 6', 6, myotp_pv_sanitize_options( array( 'otp_length' => '3' ), array() )['otp_length'] );
check( 'validity 59 falls to 300', 300, myotp_pv_sanitize_options( array( 'otp_validity' => '59' ), array() )['otp_validity'] );
check( 'validity 86400 ok', 86400, myotp_pv_sanitize_options( array( 'otp_validity' => '86400' ), array() )['otp_validity'] );
check( 'brand ok', 'Acme.Shop', myotp_pv_sanitize_options( array( 'brand' => 'Acme.Shop' ), array() )['brand'] );
check( 'bad brand keeps current', 'Acme', myotp_pv_sanitize_options( array( 'brand' => 'Acme Shop!' ), array( 'brand' => 'Acme' ) )['brand'] );
$o = myotp_pv_sanitize_options( array( 'otp_length' => 6 ), array( 'wc_enabled' => 1 ) );
check( 'missing checkbox means off', 0, $o['wc_enabled'] );
check( 'non-array input keeps current', $key, myotp_pv_sanitize_options( 'x', array( 'api_key' => $key ) )['api_key'] );
check( 'mask non-empty', MYOTP_PV_KEY_MASK, myotp_pv_mask_key( $key ) );

check( 'envelope message', 'Insufficient balance', myotp_pv_error_message( array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ), 402 ) );
check( 'flat message', 'Invalid phone', myotp_pv_error_message( array( 'status' => 'failed', 'message' => 'Invalid phone' ), 400 ) );
check( '401 fallback', "The site's MyOTP API key was rejected. Ask the site owner to check it.", myotp_pv_error_message( null, 401 ) );
check( 'html body fallback', 'nope', myotp_pv_error_message( '<html>', 500, 'nope' ) );

// ---------------------------------------------------------------- Part 2: handlers against the fakes.

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
	return myotp_test_call( array( 'MyOTP_PV_Ajax', 'send' ) );
}

function myotp_test_verify( string $otp, string $phone = '' ): MyOTP_Test_Exit {
	$_POST = array( 'otp' => $otp, 'phone' => $phone, 'nonce' => 'x' );
	return myotp_test_call( array( 'MyOTP_PV_Ajax', 'verify' ) );
}

// Nonce failure stops everything before any HTTP call.
myotp_test_configure();
$GLOBALS['myotp_test']['nonce_ok'] = false;
$r                                 = myotp_test_send( '14155551234' );
check( 'send: bad nonce refused', false, $r->success );
check( 'send: bad nonce status 403', 403, $r->status );
check( 'send: bad nonce made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );
$r = myotp_test_verify( '123456' );
check( 'verify: bad nonce refused', false, $r->success );

// No key configured.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['api_key'] = '';
$r = myotp_test_send( '14155551234' );
check( 'send: no key configured is an error', false, $r->success );
check( 'send: no key message', 'Phone verification is not configured on this site yet.', $r->data['message'] );
check( 'send: no key made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );

// Bad phone.
myotp_test_configure();
$r = myotp_test_send( '+0 12' );
check( 'send: bad phone 400', 400, $r->status );

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
check( 'send: payload phone', '14155551234', $payload['phone_number'] );
check( 'send: force_send false', false, $payload['force_send'] );
check( 'send: channel from options', 'sms', $payload['channel'] );
check( 'send: key not in response', false, strpos( json_encode( $r->data ), 'abcdefghijklmnopqrstuvwxyz012345' ) );
$store   = MyOTP_PV_Store::$instance;
$pending = null;
foreach ( $store->rows as $k => $v ) {
	if ( 0 === strpos( $k, 'pending_' ) ) {
		$pending = json_decode( $v, true );
	}
}
check( 'send: pending stored with message id', 'msg-1', $pending['message_id'] );
check( 'send: pending attempts zero', 0, $pending['attempts'] );

// 2xx without message_id is not reported as sent.
myotp_test_configure();
myotp_test_http( 200, array( 'status' => 'accepted' ) );
$r = myotp_test_send( '14155551234' );
check( 'send: 200 without message_id is an error', false, $r->success );
check( 'send: malformed message', 'The verification service gave an unexpected answer. Try again.', $r->data['message'] );

// API error surfaced as plain text.
myotp_test_configure();
myotp_test_http( 402, array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ) );
$r = myotp_test_send( '14155551234' );
check( 'send: 402 surfaced', 'Insufficient balance', $r->data['message'] );
check( 'send: 4xx keeps the slot consumed', 1, json_decode( MyOTP_PV_Store::$instance->rows[ 'send_p_14155551234' ], true )['c'] );

// Transport failure refunds the slot.
myotp_test_configure();
myotp_test_http( 'wp_error', null );
$r = myotp_test_send( '14155551234' );
check( 'send: transport error surfaced', 'Could not reach the verification service. Try again in a moment.', $r->data['message'] );
check( 'send: transport error refunded phone slot', 0, json_decode( MyOTP_PV_Store::$instance->rows['send_p_14155551234'], true )['c'] );
check( 'send: transport error refunded visitor slot', 0, json_decode( MyOTP_PV_Store::$instance->rows[ 'send_v_c_' . str_repeat( 'a', 32 ) ], true )['c'] );

// 409: existing code reused, visitor told, no pending message id.
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
$r = myotp_test_send( '14155551234' );
check( 'send: 409 is a soft success', true, $r->success );
check( 'send: 409 message', 'A code is already on its way to this number. Enter it below.', $r->data['message'] );
check( 'send: 409 flagged existing', true, $r->data['existing'] );

// Rate limit: 3 per destination trips first, then visitor at 5, then IP at 10 across rotated cookies.
myotp_test_configure();
for ( $i = 0; $i < 3; $i++ ) {
	myotp_test_http( 200, array( 'message_id' => "m$i" ) );
	$r = myotp_test_send( '14155551234' );
}
$r = myotp_test_send( '14155551234' );
check( 'limit: 4th send to same number refused', false, $r->success );
check( 'limit: status 429', 429, $r->status );
check( 'limit: no HTTP call on refusal', 3, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_http( 200, array( 'message_id' => 'm4' ) );
myotp_test_send( '14155550001' );
myotp_test_http( 200, array( 'message_id' => 'm5' ) );
myotp_test_send( '14155550002' );
$r = myotp_test_send( '14155550003' );
check( 'limit: 6th send by one visitor refused', false, $r->success );
check( 'limit: visitor refusal made no HTTP call', 5, count( $GLOBALS['myotp_test']['http_log'] ) );
// Rotate the cookie: visitor counter resets, IP counter does not.
for ( $i = 0; $i < 5; $i++ ) {
	$_COOKIE['myotp_pv_sid'] = str_repeat( 'b', 32 );
	myotp_test_http( 200, array( 'message_id' => "n$i" ) );
	$r = myotp_test_send( '1415555100' . $i );
	check( "limit: rotated cookie send $i allowed until ip cap", true, $r->success );
}
$_COOKIE['myotp_pv_sid'] = str_repeat( 'c', 32 );
$r                       = myotp_test_send( '14155552000' );
check( 'limit: 11th send from one IP refused despite new cookie', false, $r->success );
check( 'limit: ip refusal made no HTTP call', 10, count( $GLOBALS['myotp_test']['http_log'] ) );
unset( $_COOKIE['myotp_pv_sid'] );
$r = myotp_test_send( '14155552001' );
check( 'limit: no cookie at all still refused by ip', false, $r->success );

// Verify: wrong codes count down and lock; success writes a timestamped verified record.
myotp_test_configure();
$r = myotp_test_verify( '123456' );
check( 'verify: nothing pending', 'Request a code first.', $r->data['message'] );
myotp_test_http( 200, array( 'message_id' => 'msg-9' ) );
myotp_test_send( '14155551234' );
$r = myotp_test_verify( '12' );
check( 'verify: bad code shape 400', 400, $r->status );
$r = myotp_test_verify( '123456', '14155559999' );
check( 'verify: changed number refused', 'The number changed after the code was sent. Send a new code.', $r->data['message'] );
for ( $i = 1; $i <= 4; $i++ ) {
	myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
	$r = myotp_test_verify( '000000' );
	check( "verify: wrong code $i surfaced", 'Invalid OTP', $r->data['message'] );
}
check( 'verify: remaining after 5 reservations', 0, $r->data['remaining'] );
$r = myotp_test_verify( '000000' );
check( 'verify: 6th attempt locked', 429, $r->status );
check( 'verify: locked message', 'Too many wrong codes. Send a new code and try again.', $r->data['message'] );
$r = myotp_test_verify( '000000' );
check( 'verify: after lock needs new send', 'Request a code first.', $r->data['message'] );
check( 'verify: lock made no extra HTTP call', 5, count( $GLOBALS['myotp_test']['http_log'] ) );

myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-10' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify: success', true, $r->success );
check( 'verify: phone returned', '14155551234', $r->data['phone'] );
$vcall = json_decode( $GLOBALS['myotp_test']['http_log'][1]['args']['body'], true );
check( 'verify: message_id sent to API', 'msg-10', $vcall['message_id'] );
check( 'verify: url', 'https://api.myotp.app/verify_otp', $GLOBALS['myotp_test']['http_log'][1]['url'] );
check( 'verify: session reports verified', '14155551234', MyOTP_PV_Session::verified_phone() );
$record = array_values( $GLOBALS['myotp_test']['transients'] )[0];
check( 'verify: record has timestamp', true, isset( $record['at'] ) && abs( time() - $record['at'] ) < 5 );
$record['at']                                                 = time() - 1801;
$GLOBALS['myotp_test']['transients'][ array_keys( $GLOBALS['myotp_test']['transients'] )[0] ] = $record;
check( 'verify: record older than 30 min is expired', '', MyOTP_PV_Session::verified_phone() );
$r = myotp_test_verify( '482917' );
check( 'verify: pending cleared after success', 'Request a code first.', $r->data['message'] );

// Expired status drops the pending record.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-11' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'expired', 'message' => 'OTP expired' ) );
$r = myotp_test_verify( '111111' );
check( 'verify: expired surfaced', 'OTP expired', $r->data['message'] );
$r = myotp_test_verify( '111111' );
check( 'verify: expired code needs new send', 'Request a code first.', $r->data['message'] );

// Admin test: capability enforced.
myotp_test_configure();
$_POST = array( 'phone' => '14155551234', 'nonce' => 'x' );
$r     = myotp_test_call( array( 'MyOTP_PV_Ajax', 'admin_test' ) );
check( 'admin test: no capability 403', 403, $r->status );
$GLOBALS['myotp_test']['can_manage'] = true;
myotp_test_http( 200, array( 'message_id' => 'msg-t', 'status' => 'accepted', 'cost' => 1 ) );
$r = myotp_test_call( array( 'MyOTP_PV_Ajax', 'admin_test' ) );
check( 'admin test: success', true, $r->success );
check( 'admin test: message', 'Sent to 14155551234. Message ID msg-t.', $r->data['message'] );

// Registration validator and save.
myotp_test_configure();
$errors = MyOTP_PV_Registration::validate( new WP_Error(), 'bob', 'bob@example.test' );
check( 'register: unverified blocked', array( 'myotp_pv_unverified' ), $errors->get_error_codes() );
MyOTP_PV_Registration::save( 42 );
check( 'register: save without a passed validation stamps nothing', '', get_user_meta( 42, 'myotp_verified_phone', true ) );
MyOTP_PV_Session::set_verified( '14155551234' );
$_POST['myotp_pv_phone'] = '';
$errors                  = MyOTP_PV_Registration::validate( new WP_Error(), 'bob', 'bob@example.test' );
check( 'register: empty submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), $errors->get_error_codes() );
$_POST['myotp_pv_phone'] = '+1 415 555 9999';
$errors                  = MyOTP_PV_Registration::validate( new WP_Error(), 'bob', 'bob@example.test' );
check( 'register: different submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), $errors->get_error_codes() );
MyOTP_PV_Registration::save( 43 );
check( 'register: failed validation does not stamp', '', get_user_meta( 43, 'myotp_verified_phone', true ) );
$_POST['myotp_pv_phone'] = '+1 (415) 555-1234';
$errors                  = MyOTP_PV_Registration::validate( new WP_Error(), 'bob', 'bob@example.test' );
check( 'register: matching phone passes', array(), $errors->get_error_codes() );
MyOTP_PV_Registration::save( 44 );
check( 'register: passed validation stamps meta', '14155551234', get_user_meta( 44, 'myotp_verified_phone', true ) );
check( 'register: verified consumed after save', '', MyOTP_PV_Session::verified_phone() );
MyOTP_PV_Registration::save( 45 );
check( 'register: flag is single use', '', get_user_meta( 45, 'myotp_verified_phone', true ) );

// Checkout validator, stamp and consume.
myotp_test_configure();
$errors = new WP_Error();
MyOTP_PV_WooCommerce::validate( array( 'billing_phone' => '+14155551234' ), $errors );
check( 'checkout: unverified blocked', array( 'myotp_pv_unverified' ), $errors->get_error_codes() );
MyOTP_PV_Session::set_verified( '14155551234' );
$errors = new WP_Error();
MyOTP_PV_WooCommerce::validate( array( 'billing_phone' => '+1 415 555 0000' ), $errors );
check( 'checkout: different billing phone blocked', array( 'myotp_pv_mismatch' ), $errors->get_error_codes() );
$errors = new WP_Error();
MyOTP_PV_WooCommerce::validate( array( 'billing_phone' => '' ), $errors );
check( 'checkout: empty billing phone blocked', array( 'myotp_pv_mismatch' ), $errors->get_error_codes() );
$errors = new WP_Error();
MyOTP_PV_WooCommerce::validate( array( 'billing_phone' => '+1 (415) 555-1234' ), $errors );
check( 'checkout: matching billing phone passes', array(), $errors->get_error_codes() );
$order = new MyOTP_Fake_Order();
MyOTP_PV_WooCommerce::stamp_order( $order, array() );
check( 'checkout: order stamped', '14155551234', $order->meta['_myotp_verified_phone'] );
MyOTP_PV_WooCommerce::consume();
check( 'checkout: verification consumed on order creation', '', MyOTP_PV_Session::verified_phone() );
$errors = new WP_Error();
MyOTP_PV_WooCommerce::validate( array( 'billing_phone' => '+14155551234' ), $errors );
check( 'checkout: second order needs a new verification', array( 'myotp_pv_unverified' ), $errors->get_error_codes() );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in']                                       = true;
$errors                                                                   = new WP_Error();
MyOTP_PV_WooCommerce::validate( array( 'billing_phone' => '' ), $errors );
check( 'checkout: guests-only skips logged-in customers', array(), $errors->get_error_codes() );

// Privacy policy text registered.
myotp_test_configure();
myotp_pv_privacy_policy();
check( 'privacy: content registered', 'MyOTP Phone Verification', $GLOBALS['myotp_test']['privacy'][0] );
check( 'privacy: links policy', true, false !== strpos( $GLOBALS['myotp_test']['privacy'][1], 'https://myotp.app/privacy-policy/' ) );
check( 'privacy: links terms', true, false !== strpos( $GLOBALS['myotp_test']['privacy'][1], 'https://myotp.app/term-condition/' ) );

echo "\n$count checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
