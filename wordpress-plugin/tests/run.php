<?php
/**
 * Plain PHP tests for includes/functions.php. No WordPress, no composer.
 *
 * Run: php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/../myotp-phone-verification/includes/functions.php';

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

// Phone normalisation.
check( 'strip plus', '14155551234', myotp_pv_normalize_phone( '+14155551234' ) );
check( 'strip spaces, dashes, brackets, dots', '14155551234', myotp_pv_normalize_phone( '+1 (415) 555-12.34' ) );
check( 'keep leading zeros', '0044123', myotp_pv_normalize_phone( '00 44 123' ) );
check( 'digits untouched', '9647701234567', myotp_pv_normalize_phone( '9647701234567' ) );
check( 'integer input', '12345678', myotp_pv_normalize_phone( 12345678 ) );
check( 'array input is empty', '', myotp_pv_normalize_phone( array( '1' ) ) );
check( 'null input is empty', '', myotp_pv_normalize_phone( null ) );
check( 'letters removed', '123', myotp_pv_normalize_phone( 'a1b2c3' ) );

// Phone validation per API pattern ^[1-9][0-9]{6,14}$.
check( 'valid 11 digits', true, myotp_pv_is_valid_phone( '14155551234' ) );
check( 'valid 7 digits', true, myotp_pv_is_valid_phone( '1234567' ) );
check( 'valid 15 digits', true, myotp_pv_is_valid_phone( '123456789012345' ) );
check( 'too short', false, myotp_pv_is_valid_phone( '123456' ) );
check( 'too long', false, myotp_pv_is_valid_phone( '1234567890123456' ) );
check( 'leading zero invalid for API', false, myotp_pv_is_valid_phone( '0044123456' ) );
check( 'empty invalid', false, myotp_pv_is_valid_phone( '' ) );
check( 'non-string invalid', false, myotp_pv_is_valid_phone( null ) );

// OTP validation ^[0-9]{3,8}$.
check( 'otp 6 digits', true, myotp_pv_is_valid_otp( '482917' ) );
check( 'otp 3 digits', true, myotp_pv_is_valid_otp( '123' ) );
check( 'otp 8 digits', true, myotp_pv_is_valid_otp( '12345678' ) );
check( 'otp 2 digits', false, myotp_pv_is_valid_otp( '12' ) );
check( 'otp 9 digits', false, myotp_pv_is_valid_otp( '123456789' ) );
check( 'otp letters', false, myotp_pv_is_valid_otp( '12a4' ) );

// Rate limit: 5 per 600s sliding window.
$now = 1_000_000;
$r   = myotp_pv_rate_limit( array(), $now );
check( 'empty window allows', true, $r['allowed'] );
check( 'empty window keeps nothing', array(), $r['timestamps'] );
check( 'empty window retry 0', 0, $r['retry_after'] );

$r = myotp_pv_rate_limit( array( $now - 10, $now - 20, $now - 30, $now - 40 ), $now );
check( 'four sends allow fifth', true, $r['allowed'] );
check( 'four kept sorted', array( $now - 40, $now - 30, $now - 20, $now - 10 ), $r['timestamps'] );

$r = myotp_pv_rate_limit( array( $now - 10, $now - 20, $now - 30, $now - 40, $now - 50 ), $now );
check( 'five sends block sixth', false, $r['allowed'] );
check( 'retry after oldest expires', 550, $r['retry_after'] );

$r = myotp_pv_rate_limit( array( $now - 700, $now - 601, $now - 10, $now - 20, $now - 30, $now - 40 ), $now );
check( 'old sends pruned', true, $r['allowed'] );
check( 'only in-window kept', 4, count( $r['timestamps'] ) );

$r = myotp_pv_rate_limit( array( $now - 600 ), $now );
check( 'exactly window-old is pruned', array(), $r['timestamps'] );

$r = myotp_pv_rate_limit( array( $now + 100, $now - 5 ), $now );
check( 'future timestamps ignored', array( $now - 5 ), $r['timestamps'] );

$r = myotp_pv_rate_limit( array( 'x', null, $now - 5 ), $now );
check( 'garbage timestamps ignored', array( $now - 5 ), $r['timestamps'] );

$r = myotp_pv_rate_limit( array( $now - 1, $now - 2 ), $now, 2, 600 );
check( 'custom max respected', false, $r['allowed'] );
check( 'custom max retry', 598, $r['retry_after'] );

// Option sanitisation.
$key      = 'abcdefghijklmnopqrstuvwxyz012345';
$defaults = myotp_pv_default_options();
$o        = myotp_pv_sanitize_options( array(), array() );
check( 'empty input yields defaults', $defaults, $o );

$o = myotp_pv_sanitize_options( array( 'api_key' => $key ), array() );
check( 'valid key stored', $key, $o['api_key'] );

$o = myotp_pv_sanitize_options( array( 'api_key' => 'short' ), array( 'api_key' => $key ) );
check( 'invalid key keeps current', $key, $o['api_key'] );

$o = myotp_pv_sanitize_options( array( 'api_key' => MYOTP_PV_KEY_MASK ), array( 'api_key' => $key ) );
check( 'mask keeps current key', $key, $o['api_key'] );

$o = myotp_pv_sanitize_options( array( 'api_key' => '  ' . $key . ' ' ), array() );
check( 'key trimmed', $key, $o['api_key'] );

$o = myotp_pv_sanitize_options( array( 'api_key' => '' ), array( 'api_key' => $key ) );
check( 'empty key clears', '', $o['api_key'] );

$o = myotp_pv_sanitize_options( array( 'api_key' => 'bad key with spaces!!!!!!!!!!!!!' ), array() );
check( 'key with bad chars rejected', '', $o['api_key'] );

$o = myotp_pv_sanitize_options( array( 'channel' => 'WhatsApp' ), array() );
check( 'channel lowercased', 'whatsapp', $o['channel'] );
$o = myotp_pv_sanitize_options( array( 'channel' => 'pigeon' ), array( 'channel' => 'telegram' ) );
check( 'unknown channel falls back to sms', 'sms', $o['channel'] );

$o = myotp_pv_sanitize_options( array( 'otp_length' => '8' ), array() );
check( 'length 8 ok', 8, $o['otp_length'] );
$o = myotp_pv_sanitize_options( array( 'otp_length' => '3' ), array() );
check( 'length 3 falls to 6', 6, $o['otp_length'] );
$o = myotp_pv_sanitize_options( array( 'otp_length' => '9' ), array() );
check( 'length 9 falls to 6', 6, $o['otp_length'] );

$o = myotp_pv_sanitize_options( array( 'otp_validity' => '60' ), array() );
check( 'validity 60 ok', 60, $o['otp_validity'] );
$o = myotp_pv_sanitize_options( array( 'otp_validity' => '86400' ), array() );
check( 'validity 86400 ok', 86400, $o['otp_validity'] );
$o = myotp_pv_sanitize_options( array( 'otp_validity' => '59' ), array() );
check( 'validity 59 falls to 300', 300, $o['otp_validity'] );
$o = myotp_pv_sanitize_options( array( 'otp_validity' => '99999' ), array() );
check( 'validity 99999 falls to 300', 300, $o['otp_validity'] );

$o = myotp_pv_sanitize_options( array( 'brand' => 'Acme.Shop' ), array() );
check( 'brand ok', 'Acme.Shop', $o['brand'] );
$o = myotp_pv_sanitize_options( array( 'brand' => 'Acme Shop!' ), array( 'brand' => 'Acme' ) );
check( 'bad brand keeps current', 'Acme', $o['brand'] );
$o = myotp_pv_sanitize_options( array( 'brand' => 'ab' ), array() );
check( 'brand too short keeps current (empty)', '', $o['brand'] );
$o = myotp_pv_sanitize_options( array( 'brand' => '' ), array( 'brand' => 'Acme' ) );
check( 'empty brand clears', '', $o['brand'] );

$o = myotp_pv_sanitize_options( array( 'wc_enabled' => '1', 'wc_guests_only' => 'on' ), array() );
check( 'wc flags on', array( 1, 1, 0 ), array( $o['wc_enabled'], $o['wc_guests_only'], $o['register_enabled'] ) );
$o = myotp_pv_sanitize_options( array( 'otp_length' => 6 ), array( 'wc_enabled' => 1 ) );
check( 'missing checkbox means off', 0, $o['wc_enabled'] );

$o = myotp_pv_sanitize_options( 'not an array', array( 'api_key' => $key ) );
check( 'non-array input keeps current', $key, $o['api_key'] );

// Key masking.
check( 'mask empty', '', myotp_pv_mask_key( '' ) );
check( 'mask non-empty', MYOTP_PV_KEY_MASK, myotp_pv_mask_key( $key ) );

// Error message extraction.
check( 'envelope message', 'Insufficient balance', myotp_pv_error_message( array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ), 402 ) );
check( 'flat message', 'Invalid phone', myotp_pv_error_message( array( 'status' => 'failed', 'message' => 'Invalid phone' ), 400 ) );
check( 'string error', 'boom', myotp_pv_error_message( array( 'error' => 'boom' ), 400 ) );
check( '401 fallback', "The site's MyOTP API key was rejected. Ask the site owner to check it.", myotp_pv_error_message( null, 401 ) );
check( '402 fallback', "The site's MyOTP balance is too low to send a code.", myotp_pv_error_message( null, 402 ) );
check( 'generic fallback', 'nope', myotp_pv_error_message( null, 500, 'nope' ) );
check( 'html body fallback', 'nope', myotp_pv_error_message( '<html>', 500, 'nope' ) );

echo "\n$count checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
