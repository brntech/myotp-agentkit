<?php
/**
 * Plain PHP tests. Part 1 covers the pure helpers in includes/functions.php
 * over an in-memory store. Part 2 loads the plugin against the fakes in
 * wp-stubs.php, boots it through plugins_loaded, asserts every hook the
 * sources register together with the exact callable on each (both parsed
 * from the source lines, so a new or re-pointed hook cannot be missed),
 * and drives every flow through those registered callables, including the
 * interleavings the reviews asked for.
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

check( 'strip plus', '14155550123', myotp_pv_normalize_phone( '+14155550123' ) );
check( 'strip spaces, dashes, brackets, dots', '14155550123', myotp_pv_normalize_phone( '+1 (415) 555-01.23' ) );
check( 'keep leading zeros', '0044123', myotp_pv_normalize_phone( '00 44 123' ) );
check( 'array input is empty', '', myotp_pv_normalize_phone( array( '1' ) ) );
check( 'valid 11 digits', true, myotp_pv_is_valid_phone( '14155550123' ) );
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
check( 'race: count includes the interleaved take', 3, myotp_pv_take_slot( $s, 'k', $now, 5, 600 )['count'] );
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

// Conditional delete contract and guarded install.
$s = new MyOTP_Mem_Store();
$s->set( 'x', 'old', 60 );
check( 'delete with stale expected value refused', false, $s->delete( 'x', 'other' ) );
check( 'delete with matching expected value', true, $s->delete( 'x', 'old' ) );
$s = new MyOTP_Mem_Store();
check( 'install into empty', true, null !== myotp_pv_install( $s, 'k', null, array( 'a' => 1 ), 60 ) );
check( 'install into empty when row appeared loses', null, myotp_pv_install( $s, 'k', null, array( 'a' => 2 ), 60 ) );
$raw = $s->get( 'k' );
check( 'install by cas wins', true, null !== myotp_pv_install( $s, 'k', $raw, array( 'a' => 3 ), 60 ) );
check( 'install by stale cas loses', null, myotp_pv_install( $s, 'k', $raw, array( 'a' => 4 ), 60 ) );

// Attempt reservation: reserved bounds in-flight guesses, failed counts "wrong code" answers.
$s = new MyOTP_Mem_Store();
check( 'no pending: not ok', false, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['ok'] );
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155550123', 'message_id' => 'm1', 'reserved' => 0, 'failed' => 0, 'exp' => $now + 300 ) ), 300 );
for ( $i = 1; $i <= 5; $i++ ) {
	$r = myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
	check( "reservation $i ok", true, $r['ok'] );
}
check( 'reserve returns the raw it wrote', $s->get( 'pend' ), $r['raw'] );
check( 'five in flight', 5, json_decode( $s->get( 'pend' ), true )['reserved'] );
$r = myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
check( 'sixth in-flight reservation refused', false, $r['ok'] );
check( 'sixth in-flight reservation is not a lock', false, $r['locked'] );
check( 'release reserved', true, myotp_pv_release_attempt( $s, 'pend', 'm1' ) );
check( 'release on another challenge refused', false, myotp_pv_release_attempt( $s, 'pend', 'other' ) );
check( 'release never touches failed', 0, json_decode( $s->get( 'pend' ), true )['failed'] );
$f = myotp_pv_record_failed( $s, 'pend', 'm1', 5, $now );
check( 'record_failed settles one reservation', 3, json_decode( $s->get( 'pend' ), true )['reserved'] );
check( 'record_failed counts one failure', 1, $f['failed'] );
check( 'record_failed not exhausted', false, $f['exhausted'] );
check( 'record_failed on another challenge refused', false, myotp_pv_record_failed( $s, 'pend', 'other', 5, $now )['ok'] );
for ( $i = 0; $i < 3; $i++ ) {
	$f = myotp_pv_record_failed( $s, 'pend', 'm1', 5, $now );
}
check( 'four failures, not exhausted', false, $f['exhausted'] );
check( 'reserved floors at zero', 0, json_decode( $s->get( 'pend' ), true )['reserved'] );
check( 'failed + reserved at cap refuses a reservation', true, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['ok'] );
check( 'failed + reserved over cap refuses', false, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['ok'] );
$f = myotp_pv_record_failed( $s, 'pend', 'm1', 5, $now );
check( 'fifth failure is exhausted', true, $f['exhausted'] );
check( 'exhausted raw is the stored value', $s->get( 'pend' ), $f['raw'] );
check( 'reserve after exhaustion is locked', true, myotp_pv_reserve_attempt( $s, 'pend', 5, $now )['locked'] );
check( 'ttl_left from exp', 300, myotp_pv_ttl_left( array( 'exp' => $now + 300 ), $now ) );
$s->set( 'pend', 'garbage', 60 );
myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
check( 'garbage delete was guarded', array( 'pend', 'garbage' ), end( $s->deletes ) );
// Reviewer ordering: A reserves 4th, B reserves 5th, B fails (failed 4), A refunds (500): no exhaustion; then a 5th failure exhausts.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155550123', 'message_id' => 'm1', 'reserved' => 0, 'failed' => 3, 'exp' => $now + 300 ) ), 300 );
$ra = myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
$rb = myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
check( 'ordering: both reservations ok', true, $ra['ok'] && $rb['ok'] );
$fb = myotp_pv_record_failed( $s, 'pend', 'm1', 5, $now );
check( 'ordering: B failed makes 4', 4, $fb['failed'] );
check( 'ordering: not exhausted', false, $fb['exhausted'] );
check( 'ordering: A refund releases its reservation', true, myotp_pv_release_attempt( $s, 'pend', 'm1' ) );
check( 'ordering: reserved back to zero', 0, json_decode( $s->get( 'pend' ), true )['reserved'] );
check( 'ordering: still four failures', 4, json_decode( $s->get( 'pend' ), true )['failed'] );
myotp_pv_reserve_attempt( $s, 'pend', 5, $now );
check( 'ordering: fifth failure exhausts', true, myotp_pv_record_failed( $s, 'pend', 'm1', 5, $now )['exhausted'] );
// Race on record_failed: an interleaved failure is counted, not overwritten.
$s = new MyOTP_Mem_Store();
$s->set( 'pend', myotp_pv_json( array( 'phone' => '1', 'message_id' => 'm', 'reserved' => 2, 'failed' => 3, 'exp' => $now + 300 ) ), 300 );
$s->before_cas = function ( $store ) use ( $now ) {
	myotp_pv_record_failed( $store, 'pend', 'm', 5, $now );
};
check( 'failed race: retried and exhausted on the stored value', true, myotp_pv_record_failed( $s, 'pend', 'm', 5, $now )['exhausted'] );

// Cooldown record (add-only, timed).
$s = new MyOTP_Mem_Store();
check( 'no cooldown', 0, myotp_pv_lock_remaining( $s, 'cool:v:1', $now ) );
check( 'cooldown created', true, myotp_pv_lock_phone( $s, 'cool:v:1', $now, 900 ) );
check( 'cooldown remaining later', 300, myotp_pv_lock_remaining( $s, 'cool:v:1', $now + 600 ) );
check( 'second write does not shorten the first', false, myotp_pv_lock_phone( $s, 'cool:v:1', $now + 600, 900 ) );
check( 'cooldown gone after until', 0, myotp_pv_lock_remaining( $s, 'cool:v:1', $now + 900 ) );

// Verified record: replaceability, claim then consume, bound to phone and request id.
check( 'replaceable: absent', true, myotp_pv_verified_replaceable( null ) );
check( 'replaceable: verified', true, myotp_pv_verified_replaceable( myotp_pv_json( array( 'phone' => '1', 'at' => $now, 'state' => 'verified' ) ) ) );
check( 'replaceable: claiming refused', false, myotp_pv_verified_replaceable( myotp_pv_json( array( 'phone' => '1', 'at' => $now, 'state' => 'claiming:1:r' ) ) ) );
check( 'replaceable: consumed refused', false, myotp_pv_verified_replaceable( myotp_pv_json( array( 'phone' => '1', 'at' => $now, 'state' => 'consumed:order:1' ) ) ) );
check( 'verified fresh', '14155550123', myotp_pv_verified_phone_from( array( 'phone' => '14155550123', 'at' => $now - 10, 'state' => 'verified' ), $now, 1800 ) );
check( 'verified claiming reads as unverified', '', myotp_pv_verified_phone_from( array( 'phone' => '1', 'at' => $now - 5, 'state' => 'claiming:1:r' ), $now, 1800 ) );
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155550123', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
check( 'claim with another phone refused', '', myotp_pv_claim_verified( $s, 'ver', '14155550199', 'rA', $now ) );
check( 'claim with another phone left the record alone', 'verified', json_decode( $s->get( 'ver' ), true )['state'] );
check( 'first claim wins', '14155550123', myotp_pv_claim_verified( $s, 'ver', '14155550123', 'rA', $now ) );
check( 'second claim loses', '', myotp_pv_claim_verified( $s, 'ver', '14155550123', 'rB', $now ) );
check( 'consume with wrong request id loses', '', myotp_pv_consume_claim( $s, 'ver', '14155550123', 'rB', 'order:1', $now ) );
check( 'consume with the claim wins', '14155550123', myotp_pv_consume_claim( $s, 'ver', '14155550123', 'rA', 'order:1', $now ) );
check( 'consume twice loses', '', myotp_pv_consume_claim( $s, 'ver', '14155550123', 'rA', 'order:2', $now ) );
// Claim race: the row is replaced with phone B between the read and the CAS; claim of A must fail, not hijack B.
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155550123', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
$s->before_cas = function ( $store ) use ( $now ) {
	$store->set( 'ver', myotp_pv_json( array( 'phone' => '14155550199', 'at' => $now - 1, 'state' => 'verified' ) ), 1800 );
};
check( 'claim race: phone swapped under us, claim fails', '', myotp_pv_claim_verified( $s, 'ver', '14155550123', 'rA', $now ) );
check( 'claim race: B record untouched', 'verified', json_decode( $s->get( 'ver' ), true )['state'] );
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155550123', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
$won           = array();
$s->before_cas = function ( $store ) use ( $now, &$won ) {
	$won[] = myotp_pv_claim_verified( $store, 'ver', '14155550123', 'rB', $now );
};
$won[] = myotp_pv_claim_verified( $s, 'ver', '14155550123', 'rA', $now );
check( 'claim race: exactly one winner', 1, count( array_filter( $won ) ) );

check( 'send body ok', true, myotp_pv_is_send_body( array( 'message_id' => 'abc' ) ) );
check( 'send body no id', false, myotp_pv_is_send_body( array( 'status' => 'accepted' ) ) );

// Option sanitisation.
$key = 'abcdefghijklmnopqrstuvwxyz012345';
check( 'empty input yields defaults', myotp_pv_default_options(), myotp_pv_sanitize_options( array(), array() ) );
check( 'mask keeps current key', $key, myotp_pv_sanitize_options( array( 'api_key' => MYOTP_PV_KEY_MASK ), array( 'api_key' => $key ) )['api_key'] );
check( 'unknown channel falls back to sms', 'sms', myotp_pv_sanitize_options( array( 'channel' => 'pigeon' ), array( 'channel' => 'telegram' ) )['channel'] );
check( 'site cap 250', 250, myotp_pv_sanitize_options( array( 'site_hourly_cap' => '250' ), array() )['site_hourly_cap'] );
check( 'missing checkbox means off', 0, myotp_pv_sanitize_options( array( 'otp_length' => 6 ), array( 'wc_enabled' => 1 ) )['wc_enabled'] );
check( 'envelope message', 'Insufficient balance', myotp_pv_error_message( array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ), 402 ) );

// ---------------------------------------------------------------- Part 2: boot through hooks and drive them.

myotp_test_reset();
$GLOBALS['myotp_test']['options']['myotp_pv_options'] = array_merge(
	myotp_pv_default_options(),
	array( 'api_key' => $key, 'register_enabled' => 1, 'wc_enabled' => 1 )
);
myotp_test_do_action( 'plugins_loaded' );

// Every add_action / add_filter / add_shortcode in the sources, with the exact
// callable parsed from the source line, must be in the registry after boot.
$src_dir  = __DIR__ . '/../myotp-phone-verification';
$expected = array(); // hook => list of callables
$dynamic  = array(); // prefix => list of callables
foreach ( array_merge( glob( $src_dir . '/*.php' ), glob( $src_dir . '/includes/*.php' ) ) as $f ) {
	$code  = file_get_contents( $f );
	$class = preg_match( '/^class\s+(\w+)/m', $code, $cm ) ? $cm[1] : '';
	preg_match_all( "/add_(?:action|filter)\\(\\s*'([^']+)'(\\s*\\.\\s*[^,]+)?,\\s*(array\\(\\s*__CLASS__\\s*,\\s*'(\\w+)'\\s*\\)|'(\\w+)')/", $code, $m, PREG_SET_ORDER );
	foreach ( $m as $hit ) {
		$cb = '' !== $hit[4] ? array( $class, $hit[4] ) : $hit[5];
		if ( '' !== $hit[2] ) {
			$dynamic[ $hit[1] ][] = $cb;
		} else {
			$expected[ $hit[1] ][] = $cb;
		}
	}
	preg_match_all( "/add_shortcode\\(\\s*'([^']+)'\\s*,\\s*array\\(\\s*__CLASS__\\s*,\\s*'(\\w+)'\\s*\\)/", $code, $m, PREG_SET_ORDER );
	foreach ( $m as $hit ) {
		$expected[ 'shortcode:' . $hit[1] ][] = array( $class, $hit[2] );
	}
	// Every add_action/add_filter line must have matched one of the two callable shapes.
	preg_match_all( "/add_(?:action|filter)\\(\\s*'([^']+)'/", $code, $all );
	$parsed = 0;
	foreach ( $m as $x ) { // keep phpcs quiet about unused.
		$parsed++;
	}
	$named = count( $all[1] );
	$got   = 0;
	foreach ( $all[1] as $hook ) {
		if ( isset( $expected[ $hook ] ) || isset( $dynamic[ $hook ] ) ) {
			$got++;
		}
	}
	check( 'scan parsed every hook line in ' . basename( $f ), $named, $got );
}
check( 'source scan found hooks', true, count( $expected ) > 20 );
foreach ( $expected as $hook => $cbs ) {
	foreach ( $cbs as $cb ) {
		$label = is_array( $cb ) ? $cb[0] . '::' . $cb[1] : $cb;
		// The parsed callable must exist (a typo naming a missing method fails here) ...
		check( "callable exists: $label", true, is_array( $cb ) ? method_exists( $cb[0], $cb[1] ) : function_exists( $cb ) );
		// ... and the registry must hold exactly it, as something PHP can call.
		check( "hook registered with callable: $hook -> $label", true, myotp_test_has_hook( $hook, $cb ) && is_callable( $cb ) );
	}
}
foreach ( $GLOBALS['myotp_test']['hooks'] as $hook => $entries ) {
	foreach ( $entries as $entry ) {
		if ( ! is_callable( $entry[0] ) && ! ( $entry[0] instanceof Closure ) ) {
			check( "registry entry callable on $hook", true, false );
		}
	}
}
foreach ( $dynamic as $prefix => $cbs ) {
	foreach ( $cbs as $cb ) {
		$hit = false;
		foreach ( array_keys( $GLOBALS['myotp_test']['hooks'] ) as $name ) {
			if ( 0 === strpos( $name, $prefix ) && myotp_test_has_hook( $name, $cb ) ) {
				$hit = true;
			}
		}
		check( "dynamic hook registered with callable: {$prefix}* -> " . ( is_array( $cb ) ? $cb[0] . '::' . $cb[1] : $cb ), true, $hit );
	}
}
check( 'plugin_action_links_ suffix is the plugin basename', true, myotp_test_has_hook( 'plugin_action_links_' . plugin_basename( $src_dir . '/myotp-phone-verification.php' ), array( 'MyOTP_PV_Settings', 'action_links' ) ) );
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
	'woocommerce_store_api_checkout_update_order_from_request',
	'woocommerce_store_api_checkout_order_processed',
	'woocommerce_payment_complete_order_status',
	'woocommerce_order_status_changed',
	'woocommerce_thankyou',
	'woocommerce_view_order',
	'wp_ajax_myotp_pv_order_send',
	'wp_ajax_nopriv_myotp_pv_order_send',
	'wp_ajax_myotp_pv_order_verify',
	'wp_ajax_nopriv_myotp_pv_order_verify',
	'wp_footer',
	'admin_notices',
	'option_woocommerce_checkout_phone_field',
	'woocommerce_billing_fields',
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
	$raw = MyOTP_PV_Store::$instance->get( 'verified_c_' . $_COOKIE['myotp_pv_sid'] );
	return null === $raw ? null : json_decode( $raw, true );
}
function myotp_test_vset( array $rec ): void {
	MyOTP_PV_Store::$instance->set( 'verified_c_' . $_COOKIE['myotp_pv_sid'], myotp_pv_json( $rec ), 1800 );
}
function myotp_test_wrong( int $n ): void {
	for ( $i = 0; $i < $n; $i++ ) {
		myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
		myotp_test_verify( '000000' );
	}
}
function myotp_test_last_body(): array {
	$log = $GLOBALS['myotp_test']['http_log'];
	return json_decode( end( $log )['args']['body'], true );
}

// Nonce and configuration guards.
myotp_test_configure();
$GLOBALS['myotp_test']['nonce_ok'] = false;
check( 'send: bad nonce refused 403', 403, myotp_test_send( '14155550123' )->status );
check( 'send: bad nonce made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['api_key'] = '';
check( 'send: no key configured is an error', false, myotp_test_send( '14155550123' )->success );
myotp_test_configure();
check( 'send: bad phone 400', 400, myotp_test_send( '+0 12' )->status );

// Success installs a pending challenge.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = 7200;
myotp_test_http( 200, array( 'message_id' => 'msg-1', 'status' => 'accepted' ) );
$r = myotp_test_send( '+1 (415) 555-0123' );
check( 'send: success', true, $r->success );
check( 'send: X-API-Key header', 'abcdefghijklmnopqrstuvwxyz012345', $GLOBALS['myotp_test']['http_log'][0]['args']['headers']['X-API-Key'] );
check( 'send: first attempt force_send false', false, myotp_test_last_body()['force_send'] );
check( 'send: validity 7200 is sent', 7200, myotp_test_last_body()['otp_validity'] );
check( 'send: default length is not sent', false, array_key_exists( 'otp_length', myotp_test_last_body() ) );
check( 'send: pending stored with message id', 'msg-1', myotp_test_pending()['message_id'] );
check( 'send: pending expiry equals validity', true, abs( myotp_test_pending()['exp'] - ( time() + 7200 ) ) < 5 );
check( 'send: site counter taken', 1, myotp_test_counter( 'send_site' ) );

// Provider answers and slot accounting.
myotp_test_configure();
myotp_test_http( 200, array( 'status' => 'accepted' ) );
check( 'send: 200 without message_id is an error', false, myotp_test_send( '14155550123' )->success );
myotp_test_configure();
myotp_test_http( 402, array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ) );
check( 'send: 402 shows the shopper our own line, not the provider text', 'We couldn\'t send a code right now. Please try again in a minute or contact the shop.', myotp_test_send( '14155550123' )->data['message'] );
check( 'send: 402 detail kept for the shop in an admin notice', 'Sending a code failed (HTTP 402): Insufficient balance', get_transient( 'myotp_pv_api_error' )['detail'] ?? null );
check( 'send: 402 detail in the WooCommerce log', array( 'warning', 'Sending a code failed (HTTP 402): Insufficient balance', array( 'source' => 'myotp-phone-verification' ) ), $GLOBALS['myotp_test']['wc_log'][0] ?? null );
check( 'send: 4xx keeps the site slot', 1, myotp_test_counter( 'send_site' ) );
myotp_test_configure();
myotp_test_http( 503, array( 'error' => array( 'http_code' => 503, 'message' => 'Down' ) ) );
myotp_test_send( '14155550123' );
check( 'send: 5xx refunds the site slot', 0, myotp_test_counter( 'send_site' ) );
check( 'send: 5xx keeps the phone slot', 1, myotp_test_counter( 'send_p_14155550123' ) );
myotp_test_configure();
myotp_test_http( 'wp_error', null );
myotp_test_send( '14155550123' );
check( 'send: transport error refunded phone slot', 0, myotp_test_counter( 'send_p_14155550123' ) );

// 409 semantics: own challenge kept with attempts; foreign challenge -> forced resend of our own.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-2' ) );
myotp_test_send( '14155550123' );
myotp_test_wrong( 3 );
check( '409 own: three failures recorded before resend', 3, myotp_test_pending()['failed'] );
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
$r = myotp_test_send( '14155550123' );
check( '409 own: soft success', true, $r->success );
check( '409 own: failures kept', 3, myotp_test_pending()['failed'] );
check( '409 own: message id kept', 'msg-2', myotp_test_pending()['message_id'] );
check( '409 own: site slot refunded', 1, myotp_test_counter( 'send_site' ) );
check( '409 own: one provider call only', 5, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
myotp_test_http( 200, array( 'message_id' => 'msg-forced' ) );
$r = myotp_test_send( '14155550123' );
check( '409 foreign: resent and succeeded', true, $r->success );
check( '409 foreign: two provider calls', 2, count( $GLOBALS['myotp_test']['http_log'] ) );
check( '409 foreign: first call force_send false', false, json_decode( $GLOBALS['myotp_test']['http_log'][0]['args']['body'], true )['force_send'] );
check( '409 foreign: second call force_send true', true, myotp_test_last_body()['force_send'] );
check( '409 foreign: own challenge installed', 'msg-forced', myotp_test_pending()['message_id'] );
check( '409 foreign: site slot counted once', 1, myotp_test_counter( 'send_site' ) );
check( '409 foreign: still one phone slot', 1, myotp_test_counter( 'send_p_14155550123' ) );
// Forced resend that itself fails on transport refunds everything.
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409 ) ) );
myotp_test_http( 'wp_error', null );
check( '409 foreign then transport: error', false, myotp_test_send( '14155550123' )->success );
check( '409 foreign then transport: slots refunded', 0, myotp_test_counter( 'send_p_14155550123' ) );
// Forced resend still subject to caps: phone cap already spent.
myotp_test_configure();
for ( $i = 0; $i < 3; $i++ ) {
	myotp_test_http( 200, array( 'message_id' => "c$i" ) );
	myotp_test_send( '14155550123' );
}
check( '409 foreign: caps apply before the provider is asked', 429, myotp_test_send( '14155550123' )->status );

// Cooldown: five wrong codes retire this visitor's challenge; other visitors untouched; nothing keyed on the phone alone.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-3' ) );
myotp_test_send( '14155550123' );
myotp_test_wrong( 4 );
myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
$r = myotp_test_verify( '000000' );
check( 'cooldown: fifth wrong answer in our words', 'That code is not correct.', $r->data['message'] );
check( 'cooldown: remaining zero', 0, $r->data['remaining'] );
check( 'cooldown: challenge dropped', null, myotp_test_pending() );
check( 'cooldown: record is per visitor and phone', true, null !== MyOTP_PV_Store::$instance->get( 'cool:c_' . str_repeat( 'a', 32 ) . ':14155550123' ) );
$phone_only = array();
foreach ( array_keys( MyOTP_PV_Store::$instance->rows ) as $k ) {
	if ( preg_match( '/^(lock|cool):14155550123$/', $k ) ) {
		$phone_only[] = $k;
	}
}
check( 'cooldown: no record keyed on the phone alone', array(), $phone_only );
check( 'cooldown: verify refused', 400, myotp_test_verify( '000000' )->status );
$r = myotp_test_send( '14155550123' );
check( 'cooldown: send refused 429', 429, $r->status );
check( 'cooldown: message names minutes', 'Too many wrong codes for this number. Try again in 15 minutes.', $r->data['message'] );
check( 'cooldown: no HTTP call while cooling', 6, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_http( 200, array( 'message_id' => 'msg-other' ) );
check( 'cooldown: same visitor, other phone allowed', true, myotp_test_send( '14155550100' )->success );
$_COOKIE['myotp_pv_sid'] = str_repeat( 'f', 32 );
$_SERVER['REMOTE_ADDR']  = '198.51.100.7';
myotp_test_http( 200, array( 'message_id' => 'msg-victim' ) );
check( 'cooldown: the number\'s owner in another browser is not locked out', true, myotp_test_send( '14155550123' )->success );
check( 'cooldown: owner has their own challenge', 'msg-victim', myotp_test_pending()['message_id'] );
// Expiry of the cooldown allows a fresh challenge at zero attempts.
$_COOKIE['myotp_pv_sid'] = str_repeat( 'a', 32 );
MyOTP_PV_Store::$instance->rows[ 'cool:c_' . str_repeat( 'a', 32 ) . ':14155550123' ] = myotp_pv_json( array( 'at' => time() - 1000, 'until' => time() - 1 ) );
myotp_test_http( 200, array( 'message_id' => 'msg-5' ) );
check( 'cooldown: expired cooldown allows a new send', true, myotp_test_send( '14155550123' )->success );
check( 'cooldown: new challenge starts at zero failures', 0, myotp_test_pending()['failed'] );

// Only "failed" counts.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-9' ) );
myotp_test_send( '14155550123' );
check( 'count: bad code shape 400', 400, myotp_test_verify( '12' )->status );
check( 'count: changed number refused', 400, myotp_test_verify( '123456', '14155550199' )->status );
check( 'count: mismatch did not consume', array( 0, 0 ), array( myotp_test_pending()['reserved'], myotp_test_pending()['failed'] ) );
myotp_test_http( 'wp_error', null );
myotp_test_verify( '123456' );
check( 'count: transport did not consume', array( 0, 0 ), array( myotp_test_pending()['reserved'], myotp_test_pending()['failed'] ) );
myotp_test_http( 500, '<html>oops</html>' );
myotp_test_verify( '123456' );
check( 'count: 5xx did not consume', array( 0, 0 ), array( myotp_test_pending()['reserved'], myotp_test_pending()['failed'] ) );
myotp_test_http( 401, array( 'error' => array( 'http_code' => 401, 'message' => 'bad key' ) ) );
myotp_test_verify( '123456' );
check( 'count: 4xx did not consume', 0, myotp_test_pending()['failed'] );
myotp_test_http( 200, array( 'status' => 'weird', 'message' => 'unknown' ) );
myotp_test_verify( '123456' );
check( 'count: unknown status did not consume', 0, myotp_test_pending()['failed'] );
myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
$r = myotp_test_verify( '123456' );
check( 'count: failed consumed', 1, myotp_test_pending()['failed'] );
check( 'count: failed settled its reservation', 0, myotp_test_pending()['reserved'] );
check( 'count: remaining reported on failed', 4, $r->data['remaining'] );
check( 'count: message_id on the wire', 'msg-9', myotp_test_last_body()['message_id'] );
for ( $i = 0; $i < 20; $i++ ) {
	myotp_test_http( 500, '<html>oops</html>' );
	myotp_test_verify( '123456' );
}
check( 'count: a provider outage never exhausts a challenge', 1, myotp_test_pending()['failed'] );
myotp_test_http( 200, array( 'status' => 'expired', 'message' => 'OTP expired' ) );
check( 'count: expired in our words', 'That code has expired. Send a new code.', myotp_test_verify( '111111' )->data['message'] );
check( 'count: expired dropped the challenge', null, myotp_test_pending() );
check( 'count: expired started no cooldown', 0, MyOTP_PV_Session::cooldown_remaining( '14155550123' ) );

// Verify success path: must win the verified write, never over a claim.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-10' ) );
myotp_test_send( '14155550123' );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify: success', true, $r->success );
check( 'verify: record state verified', 'verified', myotp_test_vrec()['state'] );
check( 'verify: pending cleared after success', null, myotp_test_pending() );
check( 'verify: session reports verified', '14155550123', MyOTP_PV_Session::verified_phone() );
// A consumed record is history: send clears it and proceeds (repeat checkout in the same session).
myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'consumed:order:55' ) );
myotp_test_http( 200, array( 'message_id' => 'msg-again' ) );
$r = myotp_test_send( '14155550123' );
check( 'send after consume: proceeds', true, $r->success );
check( 'send after consume: consumed record cleared', null, myotp_test_vrec() );
check( 'send after consume: new challenge installed', 'msg-again', myotp_test_pending()['message_id'] );
// A claim is in flight: send refuses before taking any slot, and a verify that read the claim must not overwrite it.
myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'claiming:14155550123:rid-x' ) );
$before_site = myotp_test_counter( 'send_site' );
$r           = myotp_test_send( '14155550123' );
check( 'send during claim: refused 409', 409, $r->status );
check( 'send during claim: message', 'A checkout is using this verification. Finish it first.', $r->data['message'] );
check( 'send during claim: no provider call', 3, count( $GLOBALS['myotp_test']['http_log'] ) );
check( 'send during claim: no slot taken', $before_site, myotp_test_counter( 'send_site' ) );
// Put a challenge in place by hand (as if sent before the claim), then verify against the claim.
MyOTP_PV_Store::$instance->set( 'pending_c_' . str_repeat( 'a', 32 ), myotp_pv_json( array( 'phone' => '14155550123', 'message_id' => 'msg-11', 'attempts' => 0, 'exp' => time() + 300 ) ), 300 );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify over claim: not reported as success', false, $r->success );
check( 'verify over claim: message', 'Verification state changed. Try again.', $r->data['message'] );
check( 'verify over claim: claim intact', 'claiming:14155550123:rid-x', myotp_test_vrec()['state'] );
check( 'verify over claim: pending kept', 'msg-11', myotp_test_pending()['message_id'] );
check( 'verify over claim: reservation released', 0, myotp_test_pending()['reserved'] );
// Verified row consumed while the provider call is in flight: same outcome.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-12' ) );
myotp_test_send( '14155550123' );
$GLOBALS['myotp_test']['http_before'] = function () {
	myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'consumed:order:7' ) );
};
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify vs consume in flight: not success', 409, $r->status );
check( 'verify vs consume in flight: consumed record intact', 'consumed:order:7', myotp_test_vrec()['state'] );
check( 'verify vs consume in flight: pending kept', 'msg-12', myotp_test_pending()['message_id'] );

// Interleaving: verify A in flight while send B installs a new challenge; A must not delete B's record.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-A' ) );
myotp_test_send( '14155550123' );
$GLOBALS['myotp_test']['http_before'] = function () {
	array_unshift( $GLOBALS['myotp_test']['http_queue'], array( 200, array( 'message_id' => 'msg-B' ) ) );
	myotp_test_send( '14155550123' );
	$_POST = array( 'otp' => '482917', 'phone' => '', 'nonce' => 'x' );
};
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify race: A still succeeds', true, $r->success );
check( 'verify race: B pending survives A cleanup', 'msg-B', myotp_test_pending()['message_id'] );
check( 'verify race: A proof installed', 'verified', myotp_test_vrec()['state'] );
// Reverse: send B in flight while A's verify writes proof; B must not delete A's proof.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-A2' ) );
myotp_test_send( '14155550123' );
$GLOBALS['myotp_test']['http_before'] = function () {
	array_unshift( $GLOBALS['myotp_test']['http_queue'], array( 200, array( 'status' => 'success', 'message' => 'OK' ) ) );
	myotp_test_verify( '482917' );
	$_POST = array( 'phone' => '14155550123', 'nonce' => 'x' );
};
myotp_test_http( 200, array( 'message_id' => 'msg-B2' ) );
$r = myotp_test_send( '14155550123' );
check( 'send race: B send refused (pending changed under it)', 409, $r->status );
check( 'send race: A proof kept', 'verified', myotp_test_vrec()['state'] );
// A failed resend must not destroy a valid proof: the verified record is untouched until the provider succeeded.
myotp_test_configure();
myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'verified' ) );
myotp_test_http( 'wp_error', null );
check( 'resend transport failure: error', false, myotp_test_send( '14155550123' )->success );
check( 'resend transport failure: proof intact', 'verified', myotp_test_vrec()['state'] );
myotp_test_http( 503, array( 'error' => array( 'http_code' => 503, 'message' => 'Down' ) ) );
myotp_test_send( '14155550123' );
check( 'resend 5xx: proof intact', 'verified', myotp_test_vrec()['state'] );
$GLOBALS['myotp_test']['http_before'] = function () {
	check( 'send order: proof still present while the provider is called', 'verified', myotp_test_vrec()['state'] );
};
myotp_test_http( 200, array( 'message_id' => 'msg-C' ) );
check( 'send order: success', true, myotp_test_send( '14155550123' )->success );
check( 'send order: proof cleared after the provider succeeded', null, myotp_test_vrec() );
check( 'send order: challenge installed', 'msg-C', myotp_test_pending()['message_id'] );
// The one narrow case: a claim lands during the provider call. The SMS went out; nothing is
// refunded, no challenge is installed next to the claim, and the visitor is told to finish the checkout.
myotp_test_configure();
myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'verified' ) );
$GLOBALS['myotp_test']['http_before'] = function () {
	myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'claiming:14155550123:rid-c' ) );
};
myotp_test_http( 200, array( 'message_id' => 'msg-D' ) );
$r = myotp_test_send( '14155550123' );
check( 'send vs late claim: refused', 409, $r->status );
check( 'send vs late claim: message', 'A checkout is using this verification. Finish it first.', $r->data['message'] );
check( 'send vs late claim: claim intact', 'claiming:14155550123:rid-c', myotp_test_vrec()['state'] );
check( 'send vs late claim: no pending written', null, myotp_test_pending() );
check( 'send vs late claim: slots kept (the code went out)', 1, myotp_test_counter( 'send_p_14155550123' ) );
check( 'send vs late claim: site slot kept', 1, myotp_test_counter( 'send_site' ) );

// Admin test through its hook.
myotp_test_configure();
$_POST = array( 'phone' => '14155550123', 'nonce' => 'x' );
check( 'admin test: no capability 403', 403, myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->status );
$GLOBALS['myotp_test']['can_manage'] = true;
myotp_test_http( 200, array( 'message_id' => 'msg-t', 'status' => 'accepted', 'cost' => 1 ) );
check( 'admin test: success', 'Sent to 14155550123. Message ID msg-t.', myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->data['message'] );

// Registration: claim at validation (phone-checked), stamp then consume.
function myotp_test_register_validate( ?WP_Error $errors = null ): WP_Error {
	return apply_filters( 'registration_errors', $errors ?? new WP_Error(), 'bob', 'bob@example.test' );
}
myotp_test_configure();
check( 'register: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_register_validate()->get_error_codes() );
myotp_test_do_action( 'register_new_user', 42 );
check( 'register: save without a claim stamps nothing', '', get_user_meta( 42, 'myotp_verified_phone', true ) );
MyOTP_PV_Session::set_verified( '14155550123', null );
$_POST['myotp_pv_phone'] = '+1 415 555 9999';
check( 'register: different submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), myotp_test_register_validate()->get_error_codes() );
check( 'register: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
$_POST['myotp_pv_phone'] = '+1 (415) 555-0123';
$errors                  = new WP_Error();
$errors->add( 'username_exists', 'taken' );
check( 'register: other error means no claim', 'verified', myotp_test_register_validate( $errors ) instanceof WP_Error ? myotp_test_vrec()['state'] : '' );
check( 'register: matching phone passes', array(), myotp_test_register_validate()->get_error_codes() );
check( 'register: validation claimed the proof', 'claiming:14155550123:' . MyOTP_PV_Session::$request_id, myotp_test_vrec()['state'] );
$saved_post              = $_POST;
$_POST['myotp_pv_phone'] = '14155550100';
myotp_test_do_action( 'register_new_user', 45 );
check( 'register: other posted phone not stamped', '', get_user_meta( 45, 'myotp_verified_phone', true ) );
$_POST = $saved_post;
myotp_test_do_action( 'register_new_user', 46 );
check( 'register: consume stamps meta', '14155550123', get_user_meta( 46, 'myotp_verified_phone', true ) );
check( 'register: record consumed by user', 'consumed:user:46', myotp_test_vrec()['state'] );
myotp_test_do_action( 'register_new_user', 47 );
check( 'register: claim is single use', '', get_user_meta( 47, 'myotp_verified_phone', true ) );
// Meta write fails: claim left unconsumed, nothing stamped.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
$_POST['myotp_pv_phone'] = '14155550123';
myotp_test_register_validate();
$GLOBALS['myotp_test']['meta_fail'] = true;
myotp_test_do_action( 'register_new_user', 50 );
check( 'register meta fail: not stamped', '', get_user_meta( 50, 'myotp_verified_phone', true ) );
check( 'register meta fail: claim not consumed', 'claiming:14155550123:' . MyOTP_PV_Session::$request_id, myotp_test_vrec()['state'] );
// Consume CAS loses after the stamp: stamp removed.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
$_POST['myotp_pv_phone'] = '14155550123';
myotp_test_register_validate();
myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'consumed:order:1' ) );
myotp_test_do_action( 'register_new_user', 51 );
check( 'register consume fail: stamp removed', '', get_user_meta( 51, 'myotp_verified_phone', true ) );
check( 'register consume fail: no rollback note when rollback worked', '', get_user_meta( 51, 'myotp_verified_phone_note', true ) );
// Rollback delete fails: value blanked as a second attempt and a note stored on the account.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
$_POST['myotp_pv_phone'] = '14155550123';
myotp_test_register_validate();
myotp_test_vset( array( 'phone' => '14155550123', 'at' => time() - 5, 'state' => 'consumed:order:1' ) );
$GLOBALS['myotp_test']['delete_meta_fail'] = true;
myotp_test_do_action( 'register_new_user', 52 );
check( 'register rollback fail: value blanked', '', get_user_meta( 52, 'myotp_verified_phone', true ) );
check( 'register rollback fail: note stored', true, false !== strpos( get_user_meta( 52, 'myotp_verified_phone_note', true ), 'treat as unverified' ) );
// Two registrations sharing one proof: the second fails validation.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
$_POST['myotp_pv_phone'] = '14155550123';
check( 'register x2: first passes', array(), myotp_test_register_validate()->get_error_codes() );
MyOTP_PV_Session::$request_id = 'rid-second';
check( 'register x2: second refused', array( 'myotp_pv_claimed' ), myotp_test_register_validate()->get_error_codes() );

// Checkout: claim at validation, stamp then consume, exactly one order per proof.
function myotp_test_checkout_validate( string $phone ): WP_Error {
	$errors = new WP_Error();
	myotp_test_do_action( 'woocommerce_after_checkout_validation', array( 'billing_phone' => $phone ), $errors );
	return $errors;
}
$wc_claimed = new ReflectionProperty( 'MyOTP_PV_WooCommerce', 'claimed' );
$wc_claimed->setAccessible( true );
myotp_test_configure();
check( 'checkout: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_checkout_validate( '+14155550123' )->get_error_codes() );
MyOTP_PV_Session::set_verified( '14155550123', null );
check( 'checkout: different billing phone blocked', array( 'myotp_pv_mismatch' ), myotp_test_checkout_validate( '+1 415 555 0000' )->get_error_codes() );
check( 'checkout: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
MyOTP_PV_Session::$request_id = 'rid-A';
check( 'checkout A: matching billing phone passes', array(), myotp_test_checkout_validate( '+1 (415) 555-0123' )->get_error_codes() );
check( 'checkout A: proof claimed', 'claiming:14155550123:rid-A', myotp_test_vrec()['state'] );
MyOTP_PV_Session::$request_id = 'rid-B';
check( 'checkout B: refused at validation', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155550123' )->get_error_codes() );
MyOTP_PV_Session::$request_id = 'rid-A';
$wc_claimed->setValue( null, '14155550123' );
$order_a = new MyOTP_Fake_Order( 101 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_a );
check( 'checkout A: order stamped', '14155550123', $order_a->meta['_myotp_verified_phone'] );
check( 'checkout A: record consumed by order', 'consumed:order:101', myotp_test_vrec()['state'] );
check( 'checkout A: no note', array(), $order_a->notes );
$wc_claimed->setValue( null, '14155550123' );
$order_b = new MyOTP_Fake_Order( 102 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_b );
check( 'checkout stale: stamp written then removed', false, isset( $order_b->meta['_myotp_verified_phone'] ) );
check( 'checkout stale: saved twice (stamp, unstamp)', 2, $order_b->saved );
check( 'checkout stale: gets a note', 1, count( $order_b->notes ) );
// Rollback save throws: the second attempt blanks the value and a second note is added.
$wc_claimed->setValue( null, '14155550123' );
$order_c                   = new MyOTP_Fake_Order( 103 );
$order_c->fail_saves_after = 1; // the stamp save succeeds, the rollback save throws, the blanking save throws too
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_c );
check( 'checkout rollback fail: two notes', 2, count( $order_c->notes ) );
check( 'checkout rollback fail: second note says treat as unverified', true, false !== strpos( $order_c->notes[1], 'treat as unverified' ) );
check( 'checkout rollback fail: meta blanked in memory as second attempt', '', $order_c->meta['_myotp_verified_phone'] );
$wc_claimed->setValue( null, '14155550123' );
$order_d                   = new MyOTP_Fake_Order( 104 );
$order_d->fail_saves_after = 2; // stamp and rollback saves succeed
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_d );
check( 'checkout rollback ok: stamp removed', false, isset( $order_d->meta['_myotp_verified_phone'] ) );
check( 'checkout rollback ok: one note only', 1, count( $order_d->notes ) );
check( 'checkout: third checkout on a consumed proof is told to verify again', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155550123' )->get_error_codes() );
// Claim must be for the posted phone: a proof for another number cannot be claimed as this one.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
MyOTP_PV_Store::$instance->before_cas = function ( $store ) {
	$store->set( 'verified_c_' . str_repeat( 'a', 32 ), myotp_pv_json( array( 'phone' => '14155550199', 'at' => time() - 1, 'state' => 'verified' ) ), 1800 );
};
check( 'checkout claim race: phone swapped, validation refuses', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155550123' )->get_error_codes() );
check( 'checkout claim race: other phone record untouched', 'verified', myotp_test_vrec()['state'] );
check( 'checkout claim race: nothing claimed for this request', '', $wc_claimed->getValue() );
// Interleaving at validation: both read "verified", both CAS, exactly one passes.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
$passed                               = array();
MyOTP_PV_Store::$instance->before_cas = function () use ( &$passed ) {
	$rid                          = MyOTP_PV_Session::$request_id;
	MyOTP_PV_Session::$request_id = 'rid-other';
	$passed['other']              = myotp_test_checkout_validate( '+14155550123' )->get_error_codes();
	MyOTP_PV_Session::$request_id = $rid;
};
MyOTP_PV_Session::$request_id = 'rid-me';
$passed['me']                 = myotp_test_checkout_validate( '+14155550123' )->get_error_codes();
check( 'checkout race: interleaved request passed', array(), $passed['other'] );
check( 'checkout race: the other request refused', array( 'myotp_pv_claimed' ), $passed['me'] );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in']                                       = true;
check( 'checkout: guests-only skips logged-in customers', array(), myotp_test_checkout_validate( '' )->get_error_codes() );

// No unconditional writes on pending or verified rows.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-g' ) );
myotp_test_send( '14155550123' );
myotp_test_http( 200, array( 'status' => 'success' ) );
myotp_test_verify( '482917' );
myotp_test_http( 200, array( 'message_id' => 'msg-g2' ) );
myotp_test_send( '14155550123' );
$unguarded = array();
foreach ( MyOTP_PV_Store::$instance->deletes as $d ) {
	if ( null === $d[1] && ( 0 === strpos( $d[0], 'pending_' ) || 0 === strpos( $d[0], 'verified_' ) ) ) {
		$unguarded[] = $d[0];
	}
}
check( 'guards: no unguarded delete on pending or verified rows', array(), $unguarded );

// Block checkout (Store API): same gate, thrown as a RouteException, POST only.
function myotp_test_block_validate( string $phone, string $method = 'POST', string $route = '/wc/store/v1/checkout' ): ?Exception {
	$order                = new MyOTP_Fake_Order( 201 );
	$order->billing_phone = $phone;
	try {
		myotp_test_do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, new MyOTP_Fake_Request( $method, $route ) );
	} catch ( Exception $e ) {
		return $e;
	}
	return null;
}
myotp_test_configure();
$e = myotp_test_block_validate( '+14155550123' );
check( 'block: unverified refused with a route exception', 'myotp_pv_unverified', $e instanceof MyOTP_Fake_RouteException ? $e->error_code : null );
check( 'block: refusal is HTTP 400', 400, $e->status );
$e = myotp_test_block_validate( '' );
check( 'block: empty billing phone refused', 'myotp_pv_no_phone', $e->error_code );
MyOTP_PV_Session::set_verified( '14155550123', null );
$e = myotp_test_block_validate( '+1 415 555 0000' );
check( 'block: different phone refused', 'myotp_pv_mismatch', $e->error_code );
check( 'block: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
check( 'block: draft update (PUT) is not validated', null, myotp_test_block_validate( '+1 415 555 0000', 'PUT' ) );
check( 'block: draft update (PUT) did not claim', 'verified', myotp_test_vrec()['state'] );
MyOTP_PV_Session::$request_id = 'rid-blk-A';
check( 'block: matching phone passes validation', null, myotp_test_block_validate( '+1 (415) 555-0123' ) );
check( 'block: validation alone does not claim (a later failure must not burn the proof)', 'verified', myotp_test_vrec()['state'] );
// Claim, stamp and consume happen together once the order is processed.
$order_blk                = new MyOTP_Fake_Order( 201 );
$order_blk->billing_phone = '+1 (415) 555-0123';
myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $order_blk );
check( 'block: order stamped', '14155550123', $order_blk->meta['_myotp_verified_phone'] );
check( 'block: record consumed by order', 'consumed:order:201', myotp_test_vrec()['state'] );
// A second order on the consumed proof is refused at validation and at processing.
check( 'block: consumed proof refused at validation', 'myotp_pv_claimed', myotp_test_block_validate( '+14155550123' )->error_code );
$order_x                = new MyOTP_Fake_Order( 203 );
$order_x->billing_phone = '+14155550123';
try {
	myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $order_x );
	$thrown = null;
} catch ( Exception $e ) {
	$thrown = $e->error_code;
}
check( 'block: consumed proof refused at processing', 'myotp_pv_claimed', $thrown );
check( 'block: refused order not stamped', false, isset( $order_x->meta['_myotp_verified_phone'] ) );
// Two orders racing on one proof: the first to reach processing wins.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
MyOTP_PV_Session::$request_id = 'rid-blk-B1';
check( 'block race: both pass validation', array( null, null ), array( myotp_test_block_validate( '+14155550123' ), myotp_test_block_validate( '+14155550123' ) ) );
$o1                = new MyOTP_Fake_Order( 301 );
$o1->billing_phone = '+14155550123';
myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $o1 );
MyOTP_PV_Session::$request_id = 'rid-blk-B2';
MyOTP_PV_Session::$key_override = MyOTP_PV_Session::visitor_key(); // request B validated before A consumed
$o2                = new MyOTP_Fake_Order( 302 );
$o2->billing_phone = '+14155550123';
try {
	myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $o2 );
	$thrown = null;
} catch ( Exception $e ) {
	$thrown = $e->error_code;
}
check( 'block race: first order stamped', '14155550123', $o1->meta['_myotp_verified_phone'] );
check( 'block race: second order refused', 'myotp_pv_claimed', $thrown );
// Phone changed between validation and processing: refused.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
check( 'block: validation passes with the verified phone', null, myotp_test_block_validate( '+14155550123' ) );
$o3                = new MyOTP_Fake_Order( 303 );
$o3->billing_phone = '+14155559999';
try {
	myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $o3 );
	$thrown = null;
} catch ( Exception $e ) {
	$thrown = $e->error_code;
}
check( 'block: phone changed before processing refused', 'myotp_pv_mismatch', $thrown );
// Account created between validation and processing changes the visitor key: the proof must still be found.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155550123', null );
check( 'block key drift: validation passes as a guest', null, myotp_test_block_validate( '+14155550123' ) );
$GLOBALS['myotp_test']['logged_in'] = true; // process_customer created and logged in the account
$o_key                = new MyOTP_Fake_Order( 501 );
$o_key->billing_phone = '+14155550123';
myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $o_key );
$GLOBALS['myotp_test']['logged_in'] = false;
check( 'block key drift: order stamped', '14155550123', $o_key->meta['_myotp_verified_phone'] ?? null );
check( 'block key drift: pin released after processing', '', MyOTP_PV_Session::$key_override );
// Pay-for-order route: not gated at validation nor at processing.
myotp_test_configure();
check( 'block: pay-existing-order route not gated', null, myotp_test_block_validate( '+14155550123', 'POST', '/wc/store/v1/checkout/57' ) );
$o_pay                = new MyOTP_Fake_Order( 401 );
$o_pay->billing_phone = '+14155550123';
myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $o_pay );
check( 'block: pay-existing-order not stamped or refused', false, isset( $o_pay->meta['_myotp_verified_phone'] ) );
check( 'block: normal checkout route gated again on the next request', 'myotp_pv_unverified', myotp_test_block_validate( '+14155550123' )->error_code );
// Guests only: a logged-in shopper is not gated on block checkout either.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in'] = true;
check( 'block: guests-only skips a logged-in shopper', null, myotp_test_block_validate( '+14155550123' ) );
$GLOBALS['myotp_test']['logged_in'] = false;
check( 'block: guests-only still gates a guest', 'myotp_pv_unverified', myotp_test_block_validate( '+14155550123' )->error_code );
// Widget template only on a page holding the checkout block.
myotp_test_configure();
$GLOBALS['myotp_test']['enqueued'] = array();
ob_start();
myotp_test_do_action( 'wp_footer' );
check( 'block widget: nothing on a page without the checkout block', '', ob_get_clean() );
$GLOBALS['myotp_test']['has_block']['woocommerce/checkout'] = true;
ob_start();
myotp_test_do_action( 'wp_footer' );
$footer = ob_get_clean();
check( 'block widget: template emitted with the widget', true, false !== strpos( $footer, '<template id="myotp-pv-block-template">' ) && false !== strpos( $footer, 'myotp-pv-send' ) );
check( 'block widget: bound to the block checkout phone field', true, false !== strpos( $footer, 'data-phone-selector="#billing-phone"' ) );
check( 'block widget: injector script enqueued', true, in_array( 'myotp-pv-block', $GLOBALS['myotp_test']['enqueued'], true ) );
$GLOBALS['myotp_test']['has_block'] = array();

// ---------------------------------------------------------------- Express wallet orders (Apple Pay, Google Pay, ...).

// Detector: gateway id AND that gateway's own marker AND a known wallet type.
$gw = myotp_pv_express_wallet_defaults();
check( 'wallet: WooPayments Store API marker', 'apple_pay', myotp_pv_match_express_wallet( 'woocommerce_payments', array( 'express_payment_type' => 'apple_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: WooPayments legacy payment_request_type', 'google_pay', myotp_pv_match_express_wallet( 'woocommerce_payments', array( 'payment_request_type' => 'google_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: Stripe Store API marker', 'google_pay', myotp_pv_match_express_wallet( 'stripe', array( 'express_payment_type' => 'google_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: Stripe classic express_checkout_type', 'apple_pay', myotp_pv_match_express_wallet( 'stripe', array( 'express_checkout_type' => 'Apple_Pay ' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: Stripe legacy payment_request_api', 'payment_request_api', myotp_pv_match_express_wallet( 'stripe', array( 'payment_request_type' => 'payment_request_api' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: forged marker on cod ignored', '', myotp_pv_match_express_wallet( 'cod', array( 'express_payment_type' => 'apple_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: forged marker on bacs ignored', '', myotp_pv_match_express_wallet( 'bacs', array( 'express_payment_type' => 'apple_pay', 'express_checkout_type' => 'apple_pay', 'payment_request_type' => 'apple_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: another gateway\'s marker does not count (WooPayments has no express_checkout_type)', '', myotp_pv_match_express_wallet( 'woocommerce_payments', array( 'express_checkout_type' => 'apple_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: unknown wallet value ignored', '', myotp_pv_match_express_wallet( 'stripe', array( 'express_payment_type' => 'card' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: empty value ignored', '', myotp_pv_match_express_wallet( 'stripe', array( 'express_payment_type' => '' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: array value ignored', '', myotp_pv_match_express_wallet( 'stripe', array( 'express_payment_type' => array( 'apple_pay' ) ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: non-string gateway ignored', '', myotp_pv_match_express_wallet( array( 'stripe' ), array( 'express_payment_type' => 'apple_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: marker without gateway ignored', '', myotp_pv_match_express_wallet( '', array( 'express_payment_type' => 'apple_pay' ), $gw['gateways'], $gw['types'] ) );
check( 'wallet: payment_data flattened', array( 'payment_method' => 'card', 'express_payment_type' => 'apple_pay', 'wc-stripe-is-deferred-intent' => '1' ), myotp_pv_payment_data_fields( array( array( 'key' => 'payment_method', 'value' => 'card' ), array( 'key' => 'express_payment_type', 'value' => 'apple_pay' ), array( 'key' => 'wc-stripe-is-deferred-intent', 'value' => true ), array( 'key' => 'x', 'value' => array( 1 ) ), 'junk', array( 'value' => 'no key' ) ) ) );
check( 'wallet: payment_data non-array is empty', array(), myotp_pv_payment_data_fields( 'express_payment_type=apple_pay' ) );
check( 'wallet: bool detector', true, myotp_pv_is_express_wallet_request( 'woocommerce_payments', array( 'express_payment_type' => 'google_pay' ) ) );
// Filterable: another gateway can be added, and the added gateway still needs its marker.
add_filter(
	'myotp_pv_express_wallet_gateways',
	function ( $g ) {
		$g['acme_wallet'] = array( 'acme_express' );
		return $g;
	}
);
check( 'wallet filter: added gateway detected', 'apple_pay', myotp_pv_express_wallet_type( 'acme_wallet', array( 'acme_express' => 'apple_pay' ) ) );
check( 'wallet filter: added gateway without its marker', '', myotp_pv_express_wallet_type( 'acme_wallet', array( 'express_payment_type' => 'apple_pay' ) ) );
check( 'wallet filter: marker keys include the added one', true, in_array( 'acme_express', myotp_pv_express_marker_keys(), true ) );
unset( $GLOBALS['myotp_test']['hooks']['myotp_pv_express_wallet_gateways'] );
check( 'wallet filter: removed again', '', myotp_pv_express_wallet_type( 'acme_wallet', array( 'acme_express' => 'apple_pay' ) ) );

// Setting: default "allow" for new installs and for 1.0.0 options without the key; sanitised to the three choices.
check( 'express setting: default is allow', 'allow', myotp_pv_default_options()['wc_express'] );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options'] = array( 'api_key' => $key, 'channel' => 'sms', 'otp_length' => 6, 'otp_validity' => 300, 'brand' => '', 'wc_enabled' => 1, 'wc_guests_only' => 0, 'register_enabled' => 0, 'site_hourly_cap' => 100 );
check( 'express setting: upgrade from 1.0.0 options reads allow', 'allow', myotp_pv_get_options()['wc_express'] );
check( 'express setting: upgrade mode is allow', 'allow', MyOTP_PV_WooCommerce::express_mode() );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_express'] = 'sideways';
check( 'express setting: garbage stored value is treated as block', 'block', MyOTP_PV_WooCommerce::express_mode() );
check( 'express setting: sanitize keeps after', 'after', myotp_pv_sanitize_options( array( 'wc_express' => 'after' ), array() )['wc_express'] );
check( 'express setting: sanitize keeps block', 'block', myotp_pv_sanitize_options( array( 'wc_express' => 'BLOCK' ), array() )['wc_express'] );
check( 'express setting: sanitize refuses unknown, keeps current', 'after', myotp_pv_sanitize_options( array( 'wc_express' => '<script>' ), array( 'wc_express' => 'after' ) )['wc_express'] );
check( 'express setting: sanitize refuses array', 'allow', myotp_pv_sanitize_options( array( 'wc_express' => array( 'after' ) ), array() )['wc_express'] );
check( 'express setting: absent field keeps current', 'block', myotp_pv_sanitize_options( array( 'otp_length' => 6 ), array( 'wc_express' => 'block' ) )['wc_express'] );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_express'] = 'after';
$GLOBALS['myotp_test']['can_manage']                               = true;
ob_start();
MyOTP_PV_Settings::render();
$settings_html = ob_get_clean();
check( 'express setting: row label on the settings page', true, false !== strpos( $settings_html, 'Apple Pay / Google Pay and other express wallet orders' ) );
check( 'express setting: three choices', 3, preg_match_all( '/<option value="(allow|after|block)"/', $settings_html ) );
check( 'express setting: stored choice selected', true, (bool) preg_match( '/<option value="after"\s+selected="selected"/', $settings_html ) );

function myotp_test_set_express( string $mode ): void {
	$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_express'] = $mode;
}
/** Classic checkout: validation with posted wallet markers, then order creation. */
function myotp_test_classic_wallet( string $mode, string $gateway, array $markers, int $order_id = 601 ): array {
	myotp_test_configure();
	myotp_test_set_express( $mode );
	$_POST  = $markers;
	$errors = new WP_Error();
	myotp_test_do_action( 'woocommerce_after_checkout_validation', array( 'billing_phone' => '+14155550123', 'payment_method' => $gateway ), $errors );
	$order                = new MyOTP_Fake_Order( $order_id );
	$order->billing_phone = '+14155550123';
	if ( array() === $errors->get_error_codes() ) {
		myotp_test_do_action( 'woocommerce_checkout_order_created', $order );
	}
	return array( $errors->get_error_codes(), $order );
}
/** Block checkout: Store API POST with payment_method and payment_data, then order processing. */
function myotp_test_block_wallet( string $mode, string $gateway, array $payment_data, int $order_id = 701 ): array {
	myotp_test_configure();
	myotp_test_set_express( $mode );
	$order                = new MyOTP_Fake_Order( $order_id );
	$order->billing_phone = '+14155550123';
	$data                 = array();
	foreach ( $payment_data as $k => $v ) {
		$data[] = array( 'key' => $k, 'value' => $v );
	}
	$request = new MyOTP_Fake_Request( 'POST', '/wc/store/v1/checkout', array( 'payment_method' => $gateway, 'payment_data' => $data ) );
	try {
		myotp_test_do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $request );
		myotp_test_do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	} catch ( Exception $e ) {
		return array( $e instanceof MyOTP_Fake_RouteException ? $e->error_code : get_class( $e ), $order );
	}
	return array( null, $order );
}
function myotp_test_has_note( MyOTP_Fake_Order $order, string $needle ): bool {
	foreach ( $order->notes as $n ) {
		if ( false !== strpos( $n, $needle ) ) {
			return true;
		}
	}
	return false;
}

// Classic checkout, each mode.
list( $codes, $o ) = myotp_test_classic_wallet( 'allow', 'woocommerce_payments', array( 'express_payment_type' => 'apple_pay' ) );
check( 'classic allow: wallet order passes without a code', array(), $codes );
check( 'classic allow: order note says placed without phone verification', true, myotp_test_has_note( $o, 'express wallet payment (Apple Pay) placed without phone verification' ) );
check( 'classic allow: not stamped as verified', false, isset( $o->meta['_myotp_verified_phone'] ) );
check( 'classic allow: not held', false, isset( $o->meta['_myotp_pv_awaiting'] ) );
list( $codes, $o ) = myotp_test_classic_wallet( 'allow', 'stripe', array( 'express_checkout_type' => 'google_pay' ) );
check( 'classic allow: Stripe classic field passes', array(), $codes );
check( 'classic allow: Stripe note names Google Pay', true, myotp_test_has_note( $o, '(Google Pay)' ) );
list( $codes, $o ) = myotp_test_classic_wallet( 'after', 'stripe', array( 'payment_request_type' => 'apple_pay' ) );
check( 'classic after: wallet order passes validation', array(), $codes );
check( 'classic after: order marked as awaiting verification', 'apple_pay', $o->meta['_myotp_pv_awaiting'] ?? null );
check( 'classic after: note about the hold', true, myotp_test_has_note( $o, 'held on-hold until the shopper verifies' ) );
check( 'classic after: not stamped', false, isset( $o->meta['_myotp_verified_phone'] ) );
list( $codes, $o ) = myotp_test_classic_wallet( 'block', 'woocommerce_payments', array( 'express_payment_type' => 'apple_pay' ) );
check( 'classic block: wallet order refused like before', array( 'myotp_pv_unverified' ), $codes );
list( $codes, $o ) = myotp_test_classic_wallet( 'allow', 'cod', array( 'express_payment_type' => 'apple_pay', 'express_checkout_type' => 'apple_pay', 'payment_request_type' => 'apple_pay' ) );
check( 'classic forged: wallet marker on cash on delivery still gated', array( 'myotp_pv_unverified' ), $codes );
list( $codes, $o ) = myotp_test_classic_wallet( 'after', 'bacs', array( 'express_payment_type' => 'google_pay' ) );
check( 'classic forged: wallet marker on bank transfer still gated (after mode)', array( 'myotp_pv_unverified' ), $codes );
list( $codes, $o ) = myotp_test_classic_wallet( 'allow', 'stripe', array( 'express_payment_type' => 'card' ) );
check( 'classic: Stripe card order (no wallet type) still gated', array( 'myotp_pv_unverified' ), $codes );
list( $codes, $o ) = myotp_test_classic_wallet( 'allow', 'stripe', array() );
check( 'classic: Stripe without any marker still gated', array( 'myotp_pv_unverified' ), $codes );
// A wallet order never claims the visitor's proof, and a following normal order is gated again.
myotp_test_configure();
myotp_test_set_express( 'allow' );
MyOTP_PV_Session::set_verified( '14155550123', null );
$_POST  = array( 'express_payment_type' => 'apple_pay' );
$errors = new WP_Error();
myotp_test_do_action( 'woocommerce_after_checkout_validation', array( 'billing_phone' => '+14155550123', 'payment_method' => 'woocommerce_payments' ), $errors );
check( 'classic allow: proof left unclaimed', 'verified', myotp_test_vrec()['state'] );
$_POST = array();
check( 'classic: next normal order in the same process goes through the gate', array(), myotp_test_checkout_validate( '+14155550123' )->get_error_codes() );
check( 'classic: next normal order claimed the proof', 0, strpos( myotp_test_vrec()['state'], 'claiming:' ) );
// Guests only: a logged-in customer's wallet order is untouched (no note, no hold).
myotp_test_configure();
myotp_test_set_express( 'after' );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in'] = true;
$_POST                              = array( 'express_payment_type' => 'apple_pay' );
$errors                             = new WP_Error();
myotp_test_do_action( 'woocommerce_after_checkout_validation', array( 'billing_phone' => '+14155550123', 'payment_method' => 'woocommerce_payments' ), $errors );
$o = new MyOTP_Fake_Order( 650 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $o );
check( 'classic guests-only: logged-in wallet order not held', false, isset( $o->meta['_myotp_pv_awaiting'] ) );

// Store API (block checkout and every express button's order), each mode.
list( $err, $o ) = myotp_test_block_wallet( 'allow', 'woocommerce_payments', array( 'payment_method' => 'card', 'express_payment_type' => 'apple_pay' ) );
check( 'store api allow: WooPayments wallet order passes', null, $err );
check( 'store api allow: note', true, myotp_test_has_note( $o, 'express wallet payment (Apple Pay) placed without phone verification' ) );
check( 'store api allow: not stamped', false, isset( $o->meta['_myotp_verified_phone'] ) );
check( 'store api allow: no key pin left behind', '', MyOTP_PV_Session::$key_override );
list( $err, $o ) = myotp_test_block_wallet( 'allow', 'stripe', array( 'payment_method' => 'stripe', 'express_payment_type' => 'google_pay', 'wc-stripe-is-deferred-intent' => true ) );
check( 'store api allow: Stripe wallet order passes', null, $err );
list( $err, $o ) = myotp_test_block_wallet( 'after', 'stripe', array( 'express_payment_type' => 'link' ) );
check( 'store api after: passes', null, $err );
check( 'store api after: marked awaiting', 'link', $o->meta['_myotp_pv_awaiting'] ?? null );
check( 'store api after: not stamped', false, isset( $o->meta['_myotp_verified_phone'] ) );
list( $err, $o ) = myotp_test_block_wallet( 'block', 'woocommerce_payments', array( 'express_payment_type' => 'apple_pay' ) );
check( 'store api block: wallet order refused', 'myotp_pv_unverified', $err );
list( $err, $o ) = myotp_test_block_wallet( 'allow', 'cod', array( 'express_payment_type' => 'apple_pay' ) );
check( 'store api forged: marker on cod still gated', 'myotp_pv_unverified', $err );
check( 'store api forged: no note on the refused order', array(), $o->notes );
list( $err, $o ) = myotp_test_block_wallet( 'after', 'bacs', array( 'express_payment_type' => 'apple_pay', 'payment_request_type' => 'google_pay' ) );
check( 'store api forged: marker on bacs still gated (after mode)', 'myotp_pv_unverified', $err );
list( $err, $o ) = myotp_test_block_wallet( 'allow', 'woocommerce_payments', array( 'payment_method' => 'card' ) );
check( 'store api: WooPayments card order (no marker) still gated', 'myotp_pv_unverified', $err );
// A wallet request followed by a normal one in the same process: the normal one is gated.
myotp_test_block_wallet( 'allow', 'woocommerce_payments', array( 'express_payment_type' => 'apple_pay' ) );
check( 'store api: normal order after a wallet order is gated', 'myotp_pv_unverified', myotp_test_block_validate( '+14155550123' )->error_code );

// After payment: held, verified by the shopper on the order page, released.
function myotp_test_held_order( int $id = 801, string $wallet = 'apple_pay' ): MyOTP_Fake_Order {
	list( , $o ) = myotp_test_block_wallet( 'after', 'woocommerce_payments', array( 'express_payment_type' => $wallet ), $id );
	$o->order_key = 'wc_order_k' . $id;
	$GLOBALS['myotp_test']['orders'][ $id ] = $o;
	return $o;
}
function myotp_test_order_ajax( string $action, array $post ): MyOTP_Test_Exit {
	$_POST = array_merge( array( 'nonce' => 'x' ), $post );
	return myotp_test_ajax( 'wp_ajax_nopriv_' . $action );
}
$o = myotp_test_held_order();
check( 'after: order still pending before payment', 'pending', $o->status );
$o->payment_complete();
check( 'after: paid order goes on-hold, not processing', 'on-hold', $o->status );
check( 'after: never passed through processing', array( 'on-hold' ), $o->status_log );
check( 'after: status to restore recorded', 'processing', $o->meta['_myotp_pv_restore_status'] ?? null );
check( 'after: hold note', true, myotp_test_has_note( $o, 'payment received. The order is on hold' ) );
$o2                   = myotp_test_held_order( 802 );
$o2->needs_processing = false;
$o2->payment_complete();
check( 'after: virtual order held too', 'on-hold', $o2->status );
check( 'after: virtual order will be restored to completed', 'completed', $o2->meta['_myotp_pv_restore_status'] ?? null );
// Safety net: a gateway that sets processing directly is put back on hold; a shop manager may release by hand.
$o3 = myotp_test_held_order( 803 );
$o3->update_status( 'processing' );
check( 'after: direct processing by a gateway is put back on hold', 'on-hold', $o3->status );
check( 'after: restore status from the direct change', 'processing', $o3->meta['_myotp_pv_restore_status'] ?? null );
$o4 = myotp_test_held_order( 804 );
$o4->payment_complete();
$GLOBALS['myotp_test']['can_edit_orders'] = true;
$o4->update_status( 'processing', 'released by the shop' );
check( 'after: shop manager can release a held order by hand', 'processing', $o4->status );
$GLOBALS['myotp_test']['can_edit_orders'] = false;
// An ordinary order is never touched by the payment filter.
myotp_test_configure();
$plain = new MyOTP_Fake_Order( 805 );
$plain->payment_complete();
check( 'after: order without the marker goes to processing', 'processing', $plain->status );

// Order-received page: box only with the order's own key.
$o = myotp_test_held_order( 810 );
$o->payment_complete();
ob_start();
myotp_test_do_action( 'woocommerce_thankyou', 810 );
check( 'thank-you: no key in the URL, no box', '', ob_get_clean() );
$_GET['key'] = 'wc_order_wrong';
ob_start();
myotp_test_do_action( 'woocommerce_thankyou', 810 );
check( 'thank-you: wrong key, no box', '', ob_get_clean() );
$_GET['key'] = 'wc_order_k810';
ob_start();
myotp_test_do_action( 'woocommerce_thankyou', 810 );
$box = ob_get_clean();
check( 'thank-you: box with the right key', true, false !== strpos( $box, 'data-order="810"' ) && false !== strpos( $box, 'myotp-pv-send' ) && false !== strpos( $box, 'myotp-pv-verify' ) );
check( 'thank-you: box shows the order phone, read-only', true, false !== strpos( $box, 'value="14155550123" readonly' ) );
check( 'thank-you: box carries an order-bound nonce', true, false !== strpos( $box, 'data-nonce="' . wp_create_nonce( 'myotp_pv_order_810' ) . '"' ) );
ob_start();
myotp_test_do_action( 'woocommerce_thankyou', 999 );
check( 'thank-you: unknown order, nothing', '', ob_get_clean() );
$o->customer_id = 9; // an account order, viewed by someone else holding the key
ob_start();
myotp_test_do_action( 'woocommerce_thankyou', 810 );
check( 'thank-you: account order, not that customer, no box', '', ob_get_clean() );
// View order (My account): owner only.
$o->customer_id = 7;
ob_start();
myotp_test_do_action( 'woocommerce_view_order', 810 );
check( 'view order: logged out, no box', '', ob_get_clean() );
$GLOBALS['myotp_test']['logged_in'] = true;
ob_start();
myotp_test_do_action( 'woocommerce_view_order', 810 );
check( 'view order: owner sees the box', true, false !== strpos( ob_get_clean(), 'data-order-key="wc_order_k810"' ) );
$GLOBALS['myotp_test']['logged_in'] = false;
$o->customer_id                     = 0;
$plain                              = new MyOTP_Fake_Order( 811 );
$GLOBALS['myotp_test']['orders'][811] = $plain;
$_GET['key']                        = $plain->order_key;
ob_start();
myotp_test_do_action( 'woocommerce_thankyou', 811 );
check( 'thank-you: an order that is not held shows nothing', '', ob_get_clean() );

// Send: order key must match; one answer for every refusal; the phone comes from the order.
$o = myotp_test_held_order( 820 );
$o->payment_complete();
$GLOBALS['myotp_test']['orders'][811] = $plain;
$r = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '820', 'key' => 'wc_order_wrong', 'phone' => '14155550999' ) );
check( 'order send: wrong key refused 403', 403, $r->status );
$generic = $r->data['message'];
check( 'order send: nonce is bound to the order', 'myotp_pv_order_820', $GLOBALS['myotp_test']['nonce_action'] );
$r = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '99999', 'key' => 'wc_order_k820' ) );
check( 'order send: unknown order gets the same answer', array( 403, $generic ), array( $r->status, $r->data['message'] ) );
$r = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '811', 'key' => $plain->order_key ) );
check( 'order send: order that is not held gets the same answer', array( 403, $generic ), array( $r->status, $r->data['message'] ) );
$r = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => array( '820' ), 'key' => 'wc_order_k820' ) );
check( 'order send: array order id refused', 403, $r->status );
$r = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '820' ) );
check( 'order send: missing key refused', 403, $r->status );
check( 'order send: no provider call on any refusal', 0, count( $GLOBALS['myotp_test']['http_log'] ) );
$GLOBALS['myotp_test']['nonce_ok'] = false;
check( 'order send: bad nonce refused', 403, myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '820', 'key' => 'wc_order_k820' ) )->status );
$GLOBALS['myotp_test']['nonce_ok'] = true;
myotp_test_http( 200, array( 'message_id' => 'om-1' ) );
$r = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '820', 'key' => 'wc_order_k820', 'phone' => '14155550999' ) );
check( 'order send: success', true, $r->success );
check( 'order send: code goes to the order phone, not a posted one', '14155550123', myotp_test_last_body()['phone_number'] );
check( 'order send: pending stored per order', 'om-1', json_decode( MyOTP_PV_Store::$instance->get( 'order_pending_820' ), true )['message_id'] );
check( 'order send: counted against the phone limit', 1, myotp_test_counter( 'send_p_14155550123' ) );
check( 'order send: counted against the order limit', 1, myotp_test_counter( 'send_o_820' ) );
for ( $i = 2; $i <= 3; $i++ ) {
	myotp_test_http( 200, array( 'message_id' => "om-$i" ) );
	myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '820', 'key' => 'wc_order_k820' ) );
}
$calls = count( $GLOBALS['myotp_test']['http_log'] );
$r     = myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '820', 'key' => 'wc_order_k820' ) );
check( 'order send: fourth send refused 429', 429, $r->status );
check( 'order send: refused send made no provider call', $calls, count( $GLOBALS['myotp_test']['http_log'] ) );

// Verify: wrong phone, wrong key, wrong code, success, replay.
$o = myotp_test_held_order( 830 );
$o->payment_complete();
myotp_test_http( 200, array( 'message_id' => 'ov-1' ) );
myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '830', 'key' => 'wc_order_k830' ) );
$calls = count( $GLOBALS['myotp_test']['http_log'] );
$r     = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '830', 'key' => 'wc_order_k810', 'otp' => '123456' ) );
check( 'order verify: another order\'s key refused 403', 403, $r->status );
check( 'order verify: same answer as any refusal', $generic, $r->data['message'] );
$o->billing_phone = '+44 7700 900123';
$r                = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '830', 'key' => 'wc_order_k830', 'otp' => '123456', 'phone' => '14155550123' ) );
check( 'order verify: code sent to another number than the order holds is refused', 400, $r->status );
check( 'order verify: refused without asking the provider', $calls, count( $GLOBALS['myotp_test']['http_log'] ) );
$o->billing_phone = '+14155550123';
myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
$r = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '830', 'key' => 'wc_order_k830', 'otp' => '000000' ) );
check( 'order verify: wrong code refused', false, $r->success );
check( 'order verify: wrong code counted', 1, json_decode( MyOTP_PV_Store::$instance->get( 'order_pending_830' ), true )['failed'] );
check( 'order verify: still on hold after a wrong code', 'on-hold', $o->status );
check( 'order verify: provider checked the order phone and challenge', array( '14155550123', 'ov-1' ), array( myotp_test_last_body()['phone_number'], myotp_test_last_body()['message_id'] ) );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '830', 'key' => 'wc_order_k830', 'otp' => '482917' ) );
check( 'order verify: success', true, $r->success );
check( 'order verify: stamped like a verified order', '14155550123', $o->meta['_myotp_verified_phone'] ?? null );
check( 'order verify: back to the status the gateway set', 'processing', $o->status );
check( 'order verify: hold marker cleared', false, isset( $o->meta['_myotp_pv_awaiting'] ) || isset( $o->meta['_myotp_pv_restore_status'] ) );
check( 'order verify: verified note', true, myotp_test_has_note( $o, 'billing phone verified by the shopper' ) );
check( 'order verify: pending challenge cleared', null, MyOTP_PV_Store::$instance->get( 'order_pending_830' ) );
$notes = count( $o->notes );
$calls = count( $GLOBALS['myotp_test']['http_log'] );
$r     = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '830', 'key' => 'wc_order_k830', 'otp' => '482917' ) );
check( 'order verify: replay refused', array( false, 403 ), array( $r->success, $r->status ) );
check( 'order verify: replay made no provider call', $calls, count( $GLOBALS['myotp_test']['http_log'] ) );
check( 'order verify: replay added no note', $notes, count( $o->notes ) );
check( 'order verify: replay left the status alone', 'processing', $o->status );
check( 'order verify: replayed send refused too', 403, myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '830', 'key' => 'wc_order_k830' ) )->status );
// Two requests with the same right code: the second loses the one-time marker and stamps nothing twice.
$o = myotp_test_held_order( 840 );
$o->payment_complete();
myotp_test_http( 200, array( 'message_id' => 'ow-1' ) );
myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '840', 'key' => 'wc_order_k840' ) );
MyOTP_PV_Store::$instance->set( 'order_done_840', '1', 60 );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '840', 'key' => 'wc_order_k840', 'otp' => '482917' ) );
check( 'order verify race: loser refused 409', 409, $r->status );
check( 'order verify race: loser stamped nothing', false, isset( $o->meta['_myotp_verified_phone'] ) );
check( 'order verify race: loser changed no status', 'on-hold', $o->status );
// Verified before the payment came in: stamped now, and the later payment is not held.
$o = myotp_test_held_order( 850 );
myotp_test_http( 200, array( 'message_id' => 'op-1' ) );
myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '850', 'key' => 'wc_order_k850' ) );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '850', 'key' => 'wc_order_k850', 'otp' => '482917' ) );
check( 'order verify early: stamped', '14155550123', $o->meta['_myotp_verified_phone'] ?? null );
check( 'order verify early: status untouched while unpaid', 'pending', $o->status );
$o->payment_complete();
check( 'order verify early: payment then goes to processing', 'processing', $o->status );
// Five wrong codes cool the order down (keyed on the order, not the phone alone).
$o = myotp_test_held_order( 860 );
$o->payment_complete();
myotp_test_http( 200, array( 'message_id' => 'oc-1' ) );
myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '860', 'key' => 'wc_order_k860' ) );
for ( $i = 0; $i < 5; $i++ ) {
	myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
	$r = myotp_test_order_ajax( 'myotp_pv_order_verify', array( 'order_id' => '860', 'key' => 'wc_order_k860', 'otp' => '000000' ) );
}
check( 'order cooldown: challenge dropped after five wrong codes', null, MyOTP_PV_Store::$instance->get( 'order_pending_860' ) );
check( 'order cooldown: send refused 429', 429, myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '860', 'key' => 'wc_order_k860' ) )->status );
check( 'order cooldown: still on hold', 'on-hold', $o->status );
// Account order: key alone is not enough without the customer logged in.
$o              = myotp_test_held_order( 870 );
$o->customer_id = 7;
check( 'order send: account order needs its customer logged in', 403, myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '870', 'key' => 'wc_order_k870' ) )->status );
$GLOBALS['myotp_test']['logged_in'] = true;
myotp_test_http( 200, array( 'message_id' => 'oa-1' ) );
check( 'order send: account order with its customer', true, myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '870', 'key' => 'wc_order_k870' ) )->success );
$GLOBALS['myotp_test']['logged_in'] = false;
// An order whose billing phone cannot receive a code: a note for the shop.
$o                = new MyOTP_Fake_Order( 880 );
$o->billing_phone = '';
MyOTP_PV_Order_Verify::mark( $o, 'google_pay' );
check( 'after: unusable phone gets a note for the shop', true, myotp_test_has_note( $o, 'cannot receive a code' ) );

// Provider text never reaches shoppers; the shop gets the detail without phone numbers.
myotp_test_configure();
myotp_test_http( 400, array( 'error' => array( 'http_code' => 400, 'message' => 'brand must be an alphanumeric string of 3-16 characters' ) ) );
$r = myotp_test_send( '14155550123' );
check( 'api error: shopper sees the friendly line', 'We couldn\'t send a code right now. Please try again in a minute or contact the shop.', $r->data['message'] );
check( 'api error: raw validation text not shown to the shopper', false, false !== strpos( json_encode( $r->data ), 'alphanumeric' ) );
check( 'api error: merchant detail has the provider text', 'Sending a code failed (HTTP 400): brand must be an alphanumeric string of 3-16 characters', get_transient( 'myotp_pv_api_error' )['detail'] );
myotp_test_http( 400, array( 'error' => array( 'http_code' => 400, 'message' => 'Invalid phone number +1 (415) 555-0123 for route 14155550123' ) ) );
myotp_test_send( '14155550100' );
check( 'api error: phone numbers scrubbed from the merchant detail', 'Sending a code failed (HTTP 400): Invalid phone number [number] for route [number]', get_transient( 'myotp_pv_api_error' )['detail'] );
check( 'api error: phone numbers scrubbed from the log', false, false !== strpos( json_encode( $GLOBALS['myotp_test']['wc_log'] ), '5550123' ) );
check( 'scrub: short numbers in text survive', 'brand must be 3-16 characters', myotp_pv_scrub_detail( "brand must be\n 3-16 characters" ) );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['api_key'] = '';
check( 'api error: no key configured is the friendly line too', 'We couldn\'t send a code right now. Please try again in a minute or contact the shop.', myotp_test_send( '14155550123' )->data['message'] );
check( 'api error: no key configured is reported to the shop', 'Sending a code failed: Phone verification is not configured on this site yet.', get_transient( 'myotp_pv_api_error' )['detail'] );
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-u' ) );
myotp_test_send( '14155550123' );
myotp_test_http( 500, array( 'error' => array( 'http_code' => 500, 'message' => 'upstream timeout' ) ) );
check( 'api error: verify 5xx is the friendly check line', 'We couldn\'t check the code right now. Please try again in a minute or contact the shop.', myotp_test_verify( '123456' )->data['message'] );
myotp_test_http( 200, array( 'status' => 'weird', 'message' => 'Some provider text' ) );
check( 'api error: unknown verify status is the friendly check line', 'We couldn\'t check the code right now. Please try again in a minute or contact the shop.', myotp_test_verify( '123456' )->data['message'] );
check( 'api error: unknown verify status reported', 'Checking a code gave an unexpected answer (HTTP 200): Some provider text', get_transient( 'myotp_pv_api_error' )['detail'] );
// Admin notice for administrators only; cleared when the settings are saved.
ob_start();
myotp_test_do_action( 'admin_notices' );
check( 'api notice: hidden from users without manage_options', '', ob_get_clean() );
$GLOBALS['myotp_test']['can_manage'] = true;
ob_start();
myotp_test_do_action( 'admin_notices' );
$notice = ob_get_clean();
check( 'api notice: shown to an administrator with the detail', true, false !== strpos( $notice, 'Last error: Checking a code gave an unexpected answer (HTTP 200): Some provider text' ) && false !== strpos( $notice, 'options-general.php?page=myotp-phone-verification' ) );
MyOTP_PV_Settings::sanitize( array( 'otp_length' => 6 ) );
check( 'api notice: cleared when the settings are saved', false, get_transient( 'myotp_pv_api_error' ) );
// Brand: letters and digits only, 3 to 16; refused values keep a valid stored brand and raise a notice.
check( 'brand: letters and digits kept', 'Shop2', myotp_pv_sanitize_options( array( 'brand' => ' Shop2 ' ), array() )['brand'] );
check( 'brand: spaces refused, current kept', 'Shop', myotp_pv_sanitize_options( array( 'brand' => 'My Shop' ), array( 'brand' => 'Shop' ) )['brand'] );
check( 'brand: dots refused', '', myotp_pv_sanitize_options( array( 'brand' => 'Shop.co' ), array() )['brand'] );
check( 'brand: too short refused', '', myotp_pv_sanitize_options( array( 'brand' => 'ab' ), array() )['brand'] );
check( 'brand: an invalid stored brand is dropped on the next save', '', myotp_pv_sanitize_options( array( 'brand' => 'My Shop' ), array( 'brand' => 'Old Shop' ) )['brand'] );
check( 'brand: empty clears', '', myotp_pv_sanitize_options( array( 'brand' => '' ), array( 'brand' => 'Shop' ) )['brand'] );
check( 'brand: an invalid stored brand is never sent', false, array_key_exists( 'brand', myotp_pv_generate_payload( '14155550123', array_merge( myotp_pv_default_options(), array( 'brand' => 'My Shop' ) ), false ) ) );
myotp_test_configure();
MyOTP_PV_Settings::sanitize( array( 'brand' => 'My Shop' ) );
check( 'brand: refused value raises a settings notice', true, isset( $GLOBALS['myotp_test']['settings_errors']['brand'] ) );
myotp_test_configure();
MyOTP_PV_Settings::sanitize( array( 'brand' => 'MyShop' ) );
check( 'brand: valid value raises no notice', false, isset( $GLOBALS['myotp_test']['settings_errors']['brand'] ) );
// Order page: same friendly lines.
$o = myotp_test_held_order( 890 );
$o->payment_complete();
myotp_test_http( 402, array( 'error' => array( 'http_code' => 402, 'message' => 'Insufficient balance' ) ) );
check( 'order send: provider failure is the friendly line', 'We couldn\'t send a code right now. Please try again in a minute or contact the shop.', myotp_test_order_ajax( 'myotp_pv_order_send', array( 'order_id' => '890', 'key' => 'wc_order_k890' ) )->data['message'] );

// Phone field required at checkout while the gate is on; the stored WooCommerce option is never rewritten.
myotp_test_configure();
check( 'phone field: optional read as required at checkout', 'required', apply_filters( 'option_woocommerce_checkout_phone_field', 'optional' ) );
check( 'phone field: hidden read as required at checkout (a hidden field could never be verified)', 'required', apply_filters( 'option_woocommerce_checkout_phone_field', 'hidden' ) );
check( 'phone field: required stays required', 'required', apply_filters( 'option_woocommerce_checkout_phone_field', 'required' ) );
$GLOBALS['myotp_test']['options']['woocommerce_checkout_phone_field'] = 'optional';
check( 'phone field: stored option untouched', 'optional', get_option( 'woocommerce_checkout_phone_field' ) );
$GLOBALS['myotp_test']['is_admin'] = true;
check( 'phone field: admin screens see the merchant\'s own value', 'optional', apply_filters( 'option_woocommerce_checkout_phone_field', 'optional' ) );
$GLOBALS['myotp_test']['doing_ajax'] = true;
check( 'phone field: checkout AJAX (admin-ajax) still required', 'required', apply_filters( 'option_woocommerce_checkout_phone_field', 'optional' ) );
$GLOBALS['myotp_test']['is_admin']   = false;
$GLOBALS['myotp_test']['doing_ajax'] = false;
$bf = apply_filters( 'woocommerce_billing_fields', array( 'billing_phone' => array( 'required' => false, 'label' => 'Phone' ), 'billing_company' => array( 'required' => false ) ) );
check( 'phone field: classic billing phone required', true, $bf['billing_phone']['required'] );
check( 'phone field: other classic fields untouched', false, $bf['billing_company']['required'] );
check( 'phone field: classic without a phone field untouched', array( 'billing_email' => array() ), apply_filters( 'woocommerce_billing_fields', array( 'billing_email' => array() ) ) );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in'] = true;
check( 'phone field: guests only, logged-in customer keeps the merchant setting', 'optional', apply_filters( 'option_woocommerce_checkout_phone_field', 'optional' ) );
check( 'phone field: guests only, logged-in classic field untouched', false, apply_filters( 'woocommerce_billing_fields', array( 'billing_phone' => array( 'required' => false ) ) )['billing_phone']['required'] );
$GLOBALS['myotp_test']['logged_in'] = false;
// The settings page tells the merchant when their WooCommerce setting is overridden at checkout.
myotp_test_configure();
$GLOBALS['myotp_test']['can_manage']                                  = true;
$GLOBALS['myotp_test']['options']['woocommerce_checkout_phone_field'] = 'optional';
ob_start();
MyOTP_PV_Settings::render();
check( 'phone field: settings page says the optional field is shown as required', true, false !== strpos( ob_get_clean(), 'this plugin shows the field as required at checkout' ) );
$GLOBALS['myotp_test']['options']['woocommerce_checkout_phone_field'] = 'required';
ob_start();
MyOTP_PV_Settings::render();
check( 'phone field: no note when WooCommerce already requires it', false, false !== strpos( ob_get_clean(), 'this plugin shows the field as required at checkout' ) );

// Privacy text, header, .pot.
myotp_test_configure();
myotp_test_do_action( 'admin_init' );
check( 'privacy: content registered', 'MyOTP Phone Verification', $GLOBALS['myotp_test']['privacy'][0] );
check( 'privacy: mentions the cooldown', true, false !== strpos( $GLOBALS['myotp_test']['privacy'][1], '15-minute cooldown' ) );
$header = file_get_contents( $src_dir . '/myotp-phone-verification.php' );
preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', $header, $m );
check( 'header: description under 140 chars', true, strlen( trim( $m[1] ) ) < 140 );
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

// generate_otp body: gated fields only when they differ from the API default.
$d  = myotp_pv_default_options();
$pl = myotp_pv_generate_payload( '14155550123', $d, false );
check( 'payload: defaults send no otp_length', false, array_key_exists( 'otp_length', $pl ) );
check( 'payload: defaults send no otp_validity', false, array_key_exists( 'otp_validity', $pl ) );
check( 'payload: defaults send no brand', false, array_key_exists( 'brand', $pl ) );
check( 'payload: phone, channel, force', array( '14155550123', 'sms', true ), array( $pl['phone_number'], $pl['channel'], myotp_pv_generate_payload( '14155550123', $d, true )['force_send'] ) );
$pl = myotp_pv_generate_payload( '14155550123', array_merge( $d, array( 'otp_length' => 4, 'otp_validity' => 600, 'brand' => 'Shop' ) ), false );
check( 'payload: custom length sent', 4, $pl['otp_length'] );
check( 'payload: custom validity sent', 600, $pl['otp_validity'] );
check( 'payload: brand sent', 'Shop', $pl['brand'] );
check( 'payload: validity above API max clamped', 14400, myotp_pv_generate_payload( '1', array_merge( $d, array( 'otp_validity' => 86400 ) ), false )['otp_validity'] );
check( 'payload: telegram validity clamped to 3600', 3600, myotp_pv_generate_payload( '1', array_merge( $d, array( 'channel' => 'telegram', 'otp_validity' => 7200 ) ), false )['otp_validity'] );
check( 'payload: validity below 60 raised', 60, myotp_pv_generate_payload( '1', array_merge( $d, array( 'otp_validity' => 10 ) ), false )['otp_validity'] );
check( 'sanitize: validity above 14400 capped at 14400', 14400, myotp_pv_sanitize_options( array( 'otp_validity' => 86400 ), array() )['otp_validity'] );
check( 'sanitize: validity below 60 falls back to 300', 300, myotp_pv_sanitize_options( array( 'otp_validity' => 10 ), array() )['otp_validity'] );
check( 'sanitize: telegram 7200 saved as 3600', 3600, myotp_pv_sanitize_options( array( 'channel' => 'telegram', 'otp_validity' => 7200 ), array() )['otp_validity'] );
check( 'sanitize: switching to telegram caps stored 7200', 3600, myotp_pv_sanitize_options( array( 'channel' => 'telegram' ), array( 'otp_validity' => 7200 ) )['otp_validity'] );
check( 'sanitize: sms 7200 kept', 7200, myotp_pv_sanitize_options( array( 'channel' => 'sms', 'otp_validity' => 7200 ), array() )['otp_validity'] );
// The pending record lives exactly as long as the validity that was sent.
foreach ( array( array( 'telegram', 7200, 3600 ), array( 'sms', 86400, 14400 ), array( 'whatsapp', 600, 600 ) ) as $case ) {
	myotp_test_configure();
	$GLOBALS['myotp_test']['options']['myotp_pv_options']['channel']      = $case[0];
	$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = $case[1];
	myotp_test_http( 200, array( 'message_id' => 'msg-ttl', 'status' => 'accepted' ) );
	myotp_test_send( '14155550123' );
	check( "pending ttl: {$case[0]} {$case[1]} sent as {$case[2]}", $case[2], myotp_test_last_body()['otp_validity'] );
	check( "pending ttl: {$case[0]} {$case[1]} expires after {$case[2]}", true, abs( myotp_test_pending()['exp'] - ( time() + $case[2] ) ) < 5 );
}
// A stored value above the new ceiling is shown as the value actually sent.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = 86400;
$GLOBALS['myotp_test']['can_manage'] = true;
ob_start();
MyOTP_PV_Settings::render();
$settings_html = ob_get_clean();
check( 'settings: legacy validity shown capped', true, false !== strpos( $settings_html, '[otp_validity]" value="14400"' ) );
$GLOBALS['myotp_test']['options']['myotp_pv_options']['channel']      = 'telegram';
$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = 7200;
ob_start();
MyOTP_PV_Settings::render();
$settings_html = ob_get_clean();
check( 'settings: telegram 7200 shown as 3600', true, false !== strpos( $settings_html, '[otp_validity]" value="3600"' ) );
check( 'sanitize: validity 14400 kept', 14400, myotp_pv_sanitize_options( array( 'otp_validity' => 14400 ), array() )['otp_validity'] );

// WordPress.org directory rules: direct-access guard, readme headers.
$unguarded_files = array();
foreach ( array_merge( glob( $src_dir . '/*.php' ), glob( $src_dir . '/includes/*.php' ) ) as $f ) {
	$head = substr( file_get_contents( $f ), 0, 2000 );
	if ( ! preg_match( "/if \\( ! defined\\( '(ABSPATH|WP_UNINSTALL_PLUGIN)' \\) \\) \\{\\s*exit;/", $head ) ) {
		$unguarded_files[] = basename( $f );
	}
}
check( 'wporg: every PHP file exits on direct access', array(), $unguarded_files );
$all_src = '';
foreach ( array_merge( glob( $src_dir . '/*.php' ), glob( $src_dir . '/includes/*.php' ) ) as $f ) {
	$all_src .= file_get_contents( $f );
}
check( 'wporg: no load_plugin_textdomain call', false, (bool) preg_match( '/\bload_plugin_textdomain\s*\(/', $all_src ) );
$readme = file_get_contents( $src_dir . '/readme.txt' );
$rh     = function ( $name ) use ( $readme ) {
	return preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.+?)\s*$/mi', $readme, $mm ) ? $mm[1] : null;
};
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $mv );
preg_match( '/^\s*\*\s*Requires PHP:\s*(\S+)/m', $header, $mp );
preg_match( '/^\s*\*\s*Requires at least:\s*(\S+)/m', $header, $mr );
check( 'readme: stable tag equals plugin version', $mv[1], $rh( 'Stable tag' ) );
check( 'readme: plugin version equals MYOTP_PV_VERSION', MYOTP_PV_VERSION, $mv[1] );
check( 'readme: contributors', 'rayasoren', $rh( 'Contributors' ) );
check( 'readme: requires PHP matches header', $mp[1], $rh( 'Requires PHP' ) );
check( 'readme: requires at least matches header', $mr[1], $rh( 'Requires at least' ) );
check( 'readme: license', 'GPLv2 or later', $rh( 'License' ) );
check( 'readme: tested up to is a version', true, (bool) preg_match( '/^\d+\.\d+(\.\d+)?$/', (string) $rh( 'Tested up to' ) ) );
check( 'readme: at most 5 tags', true, count( array_filter( array_map( 'trim', explode( ',', (string) $rh( 'Tags' ) ) ) ) ) <= 5 );
preg_match( '/^=== .+ ===\R(?:[^\r\n]+\R)+\R([^\r\n]+)/', $readme, $ms );
check( 'readme: short description at most 150 chars', true, isset( $ms[1] ) && strlen( $ms[1] ) <= 150 );
check( 'readme: changelog has the stable version', true, false !== strpos( $readme, '= ' . $mv[1] . ' =' ) );

echo "\n$count checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
