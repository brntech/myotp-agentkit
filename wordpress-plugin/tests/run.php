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
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'm1', 'reserved' => 0, 'failed' => 0, 'exp' => $now + 300 ) ), 300 );
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
$s->set( 'pend', myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'm1', 'reserved' => 0, 'failed' => 3, 'exp' => $now + 300 ) ), 300 );
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
check( 'verified fresh', '14155551234', myotp_pv_verified_phone_from( array( 'phone' => '14155551234', 'at' => $now - 10, 'state' => 'verified' ), $now, 1800 ) );
check( 'verified claiming reads as unverified', '', myotp_pv_verified_phone_from( array( 'phone' => '1', 'at' => $now - 5, 'state' => 'claiming:1:r' ), $now, 1800 ) );
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
check( 'claim with another phone refused', '', myotp_pv_claim_verified( $s, 'ver', '14155559999', 'rA', $now ) );
check( 'claim with another phone left the record alone', 'verified', json_decode( $s->get( 'ver' ), true )['state'] );
check( 'first claim wins', '14155551234', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now ) );
check( 'second claim loses', '', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rB', $now ) );
check( 'consume with wrong request id loses', '', myotp_pv_consume_claim( $s, 'ver', '14155551234', 'rB', 'order:1', $now ) );
check( 'consume with the claim wins', '14155551234', myotp_pv_consume_claim( $s, 'ver', '14155551234', 'rA', 'order:1', $now ) );
check( 'consume twice loses', '', myotp_pv_consume_claim( $s, 'ver', '14155551234', 'rA', 'order:2', $now ) );
// Claim race: the row is replaced with phone B between the read and the CAS; claim of A must fail, not hijack B.
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
$s->before_cas = function ( $store ) use ( $now ) {
	$store->set( 'ver', myotp_pv_json( array( 'phone' => '14155559999', 'at' => $now - 1, 'state' => 'verified' ) ), 1800 );
};
check( 'claim race: phone swapped under us, claim fails', '', myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now ) );
check( 'claim race: B record untouched', 'verified', json_decode( $s->get( 'ver' ), true )['state'] );
$s = new MyOTP_Mem_Store();
$s->set( 'ver', myotp_pv_json( array( 'phone' => '14155551234', 'at' => $now - 5, 'state' => 'verified' ) ), 1800 );
$won           = array();
$s->before_cas = function ( $store ) use ( $now, &$won ) {
	$won[] = myotp_pv_claim_verified( $store, 'ver', '14155551234', 'rB', $now );
};
$won[] = myotp_pv_claim_verified( $s, 'ver', '14155551234', 'rA', $now );
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
check( 'send: bad nonce refused 403', 403, myotp_test_send( '14155551234' )->status );
check( 'send: bad nonce made no HTTP call', 0, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['api_key'] = '';
check( 'send: no key configured is an error', false, myotp_test_send( '14155551234' )->success );
myotp_test_configure();
check( 'send: bad phone 400', 400, myotp_test_send( '+0 12' )->status );

// Success installs a pending challenge.
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['otp_validity'] = 7200;
myotp_test_http( 200, array( 'message_id' => 'msg-1', 'status' => 'accepted' ) );
$r = myotp_test_send( '+1 (415) 555-1234' );
check( 'send: success', true, $r->success );
check( 'send: X-API-Key header', 'abcdefghijklmnopqrstuvwxyz012345', $GLOBALS['myotp_test']['http_log'][0]['args']['headers']['X-API-Key'] );
check( 'send: first attempt force_send false', false, myotp_test_last_body()['force_send'] );
check( 'send: pending stored with message id', 'msg-1', myotp_test_pending()['message_id'] );
check( 'send: pending expiry equals validity', true, abs( myotp_test_pending()['exp'] - ( time() + 7200 ) ) < 5 );
check( 'send: site counter taken', 1, myotp_test_counter( 'send_site' ) );

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

// 409 semantics: own challenge kept with attempts; foreign challenge -> forced resend of our own.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-2' ) );
myotp_test_send( '14155551234' );
myotp_test_wrong( 3 );
check( '409 own: three failures recorded before resend', 3, myotp_test_pending()['failed'] );
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
$r = myotp_test_send( '14155551234' );
check( '409 own: soft success', true, $r->success );
check( '409 own: failures kept', 3, myotp_test_pending()['failed'] );
check( '409 own: message id kept', 'msg-2', myotp_test_pending()['message_id'] );
check( '409 own: site slot refunded', 1, myotp_test_counter( 'send_site' ) );
check( '409 own: one provider call only', 5, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409, 'message' => 'OTP already active' ) ) );
myotp_test_http( 200, array( 'message_id' => 'msg-forced' ) );
$r = myotp_test_send( '14155551234' );
check( '409 foreign: resent and succeeded', true, $r->success );
check( '409 foreign: two provider calls', 2, count( $GLOBALS['myotp_test']['http_log'] ) );
check( '409 foreign: first call force_send false', false, json_decode( $GLOBALS['myotp_test']['http_log'][0]['args']['body'], true )['force_send'] );
check( '409 foreign: second call force_send true', true, myotp_test_last_body()['force_send'] );
check( '409 foreign: own challenge installed', 'msg-forced', myotp_test_pending()['message_id'] );
check( '409 foreign: site slot counted once', 1, myotp_test_counter( 'send_site' ) );
check( '409 foreign: still one phone slot', 1, myotp_test_counter( 'send_p_14155551234' ) );
// Forced resend that itself fails on transport refunds everything.
myotp_test_configure();
myotp_test_http( 409, array( 'error' => array( 'http_code' => 409 ) ) );
myotp_test_http( 'wp_error', null );
check( '409 foreign then transport: error', false, myotp_test_send( '14155551234' )->success );
check( '409 foreign then transport: slots refunded', 0, myotp_test_counter( 'send_p_14155551234' ) );
// Forced resend still subject to caps: phone cap already spent.
myotp_test_configure();
for ( $i = 0; $i < 3; $i++ ) {
	myotp_test_http( 200, array( 'message_id' => "c$i" ) );
	myotp_test_send( '14155551234' );
}
check( '409 foreign: caps apply before the provider is asked', 429, myotp_test_send( '14155551234' )->status );

// Cooldown: five wrong codes retire this visitor's challenge; other visitors untouched; nothing keyed on the phone alone.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-3' ) );
myotp_test_send( '14155551234' );
myotp_test_wrong( 4 );
myotp_test_http( 200, array( 'status' => 'failed', 'message' => 'Invalid OTP' ) );
$r = myotp_test_verify( '000000' );
check( 'cooldown: fifth wrong answer surfaced', 'Invalid OTP', $r->data['message'] );
check( 'cooldown: remaining zero', 0, $r->data['remaining'] );
check( 'cooldown: challenge dropped', null, myotp_test_pending() );
check( 'cooldown: record is per visitor and phone', true, null !== MyOTP_PV_Store::$instance->get( 'cool:c_' . str_repeat( 'a', 32 ) . ':14155551234' ) );
$phone_only = array();
foreach ( array_keys( MyOTP_PV_Store::$instance->rows ) as $k ) {
	if ( preg_match( '/^(lock|cool):14155551234$/', $k ) ) {
		$phone_only[] = $k;
	}
}
check( 'cooldown: no record keyed on the phone alone', array(), $phone_only );
check( 'cooldown: verify refused', 400, myotp_test_verify( '000000' )->status );
$r = myotp_test_send( '14155551234' );
check( 'cooldown: send refused 429', 429, $r->status );
check( 'cooldown: message names minutes', 'Too many wrong codes for this number. Try again in 15 minutes.', $r->data['message'] );
check( 'cooldown: no HTTP call while cooling', 6, count( $GLOBALS['myotp_test']['http_log'] ) );
myotp_test_http( 200, array( 'message_id' => 'msg-other' ) );
check( 'cooldown: same visitor, other phone allowed', true, myotp_test_send( '14155550000' )->success );
$_COOKIE['myotp_pv_sid'] = str_repeat( 'f', 32 );
$_SERVER['REMOTE_ADDR']  = '198.51.100.7';
myotp_test_http( 200, array( 'message_id' => 'msg-victim' ) );
check( 'cooldown: the number\'s owner in another browser is not locked out', true, myotp_test_send( '14155551234' )->success );
check( 'cooldown: owner has their own challenge', 'msg-victim', myotp_test_pending()['message_id'] );
// Expiry of the cooldown allows a fresh challenge at zero attempts.
$_COOKIE['myotp_pv_sid'] = str_repeat( 'a', 32 );
MyOTP_PV_Store::$instance->rows[ 'cool:c_' . str_repeat( 'a', 32 ) . ':14155551234' ] = myotp_pv_json( array( 'at' => time() - 1000, 'until' => time() - 1 ) );
myotp_test_http( 200, array( 'message_id' => 'msg-5' ) );
check( 'cooldown: expired cooldown allows a new send', true, myotp_test_send( '14155551234' )->success );
check( 'cooldown: new challenge starts at zero failures', 0, myotp_test_pending()['failed'] );

// Only "failed" counts.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-9' ) );
myotp_test_send( '14155551234' );
check( 'count: bad code shape 400', 400, myotp_test_verify( '12' )->status );
check( 'count: changed number refused', 400, myotp_test_verify( '123456', '14155559999' )->status );
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
check( 'count: expired surfaced', 'OTP expired', myotp_test_verify( '111111' )->data['message'] );
check( 'count: expired dropped the challenge', null, myotp_test_pending() );
check( 'count: expired started no cooldown', 0, MyOTP_PV_Session::cooldown_remaining( '14155551234' ) );

// Verify success path: must win the verified write, never over a claim.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-10' ) );
myotp_test_send( '14155551234' );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify: success', true, $r->success );
check( 'verify: record state verified', 'verified', myotp_test_vrec()['state'] );
check( 'verify: pending cleared after success', null, myotp_test_pending() );
check( 'verify: session reports verified', '14155551234', MyOTP_PV_Session::verified_phone() );
// A consumed record is history: send clears it and proceeds (repeat checkout in the same session).
myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'consumed:order:55' ) );
myotp_test_http( 200, array( 'message_id' => 'msg-again' ) );
$r = myotp_test_send( '14155551234' );
check( 'send after consume: proceeds', true, $r->success );
check( 'send after consume: consumed record cleared', null, myotp_test_vrec() );
check( 'send after consume: new challenge installed', 'msg-again', myotp_test_pending()['message_id'] );
// A claim is in flight: send refuses before taking any slot, and a verify that read the claim must not overwrite it.
myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'claiming:14155551234:rid-x' ) );
$before_site = myotp_test_counter( 'send_site' );
$r           = myotp_test_send( '14155551234' );
check( 'send during claim: refused 409', 409, $r->status );
check( 'send during claim: message', 'A checkout is using this verification. Finish it first.', $r->data['message'] );
check( 'send during claim: no provider call', 3, count( $GLOBALS['myotp_test']['http_log'] ) );
check( 'send during claim: no slot taken', $before_site, myotp_test_counter( 'send_site' ) );
// Put a challenge in place by hand (as if sent before the claim), then verify against the claim.
MyOTP_PV_Store::$instance->set( 'pending_c_' . str_repeat( 'a', 32 ), myotp_pv_json( array( 'phone' => '14155551234', 'message_id' => 'msg-11', 'attempts' => 0, 'exp' => time() + 300 ) ), 300 );
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify over claim: not reported as success', false, $r->success );
check( 'verify over claim: message', 'Verification state changed. Try again.', $r->data['message'] );
check( 'verify over claim: claim intact', 'claiming:14155551234:rid-x', myotp_test_vrec()['state'] );
check( 'verify over claim: pending kept', 'msg-11', myotp_test_pending()['message_id'] );
check( 'verify over claim: reservation released', 0, myotp_test_pending()['reserved'] );
// Verified row consumed while the provider call is in flight: same outcome.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-12' ) );
myotp_test_send( '14155551234' );
$GLOBALS['myotp_test']['http_before'] = function () {
	myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'consumed:order:7' ) );
};
myotp_test_http( 200, array( 'status' => 'success', 'message' => 'OK' ) );
$r = myotp_test_verify( '482917' );
check( 'verify vs consume in flight: not success', 409, $r->status );
check( 'verify vs consume in flight: consumed record intact', 'consumed:order:7', myotp_test_vrec()['state'] );
check( 'verify vs consume in flight: pending kept', 'msg-12', myotp_test_pending()['message_id'] );

// Interleaving: verify A in flight while send B installs a new challenge; A must not delete B's record.
myotp_test_configure();
myotp_test_http( 200, array( 'message_id' => 'msg-A' ) );
myotp_test_send( '14155551234' );
$GLOBALS['myotp_test']['http_before'] = function () {
	array_unshift( $GLOBALS['myotp_test']['http_queue'], array( 200, array( 'message_id' => 'msg-B' ) ) );
	myotp_test_send( '14155551234' );
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
// A failed resend must not destroy a valid proof: the verified record is untouched until the provider succeeded.
myotp_test_configure();
myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'verified' ) );
myotp_test_http( 'wp_error', null );
check( 'resend transport failure: error', false, myotp_test_send( '14155551234' )->success );
check( 'resend transport failure: proof intact', 'verified', myotp_test_vrec()['state'] );
myotp_test_http( 503, array( 'error' => array( 'http_code' => 503, 'message' => 'Down' ) ) );
myotp_test_send( '14155551234' );
check( 'resend 5xx: proof intact', 'verified', myotp_test_vrec()['state'] );
$GLOBALS['myotp_test']['http_before'] = function () {
	check( 'send order: proof still present while the provider is called', 'verified', myotp_test_vrec()['state'] );
};
myotp_test_http( 200, array( 'message_id' => 'msg-C' ) );
check( 'send order: success', true, myotp_test_send( '14155551234' )->success );
check( 'send order: proof cleared after the provider succeeded', null, myotp_test_vrec() );
check( 'send order: challenge installed', 'msg-C', myotp_test_pending()['message_id'] );
// The one narrow case: a claim lands during the provider call. The SMS went out; nothing is
// refunded, no challenge is installed next to the claim, and the visitor is told to finish the checkout.
myotp_test_configure();
myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'verified' ) );
$GLOBALS['myotp_test']['http_before'] = function () {
	myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'claiming:14155551234:rid-c' ) );
};
myotp_test_http( 200, array( 'message_id' => 'msg-D' ) );
$r = myotp_test_send( '14155551234' );
check( 'send vs late claim: refused', 409, $r->status );
check( 'send vs late claim: message', 'A checkout is using this verification. Finish it first.', $r->data['message'] );
check( 'send vs late claim: claim intact', 'claiming:14155551234:rid-c', myotp_test_vrec()['state'] );
check( 'send vs late claim: no pending written', null, myotp_test_pending() );
check( 'send vs late claim: slots kept (the code went out)', 1, myotp_test_counter( 'send_p_14155551234' ) );
check( 'send vs late claim: site slot kept', 1, myotp_test_counter( 'send_site' ) );

// Admin test through its hook.
myotp_test_configure();
$_POST = array( 'phone' => '14155551234', 'nonce' => 'x' );
check( 'admin test: no capability 403', 403, myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->status );
$GLOBALS['myotp_test']['can_manage'] = true;
myotp_test_http( 200, array( 'message_id' => 'msg-t', 'status' => 'accepted', 'cost' => 1 ) );
check( 'admin test: success', 'Sent to 14155551234. Message ID msg-t.', myotp_test_ajax( 'wp_ajax_myotp_pv_test' )->data['message'] );

// Registration: claim at validation (phone-checked), stamp then consume.
function myotp_test_register_validate( ?WP_Error $errors = null ): WP_Error {
	return apply_filters( 'registration_errors', $errors ?? new WP_Error(), 'bob', 'bob@example.test' );
}
myotp_test_configure();
check( 'register: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_register_validate()->get_error_codes() );
myotp_test_do_action( 'register_new_user', 42 );
check( 'register: save without a claim stamps nothing', '', get_user_meta( 42, 'myotp_verified_phone', true ) );
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '+1 415 555 9999';
check( 'register: different submitted phone is a mismatch', array( 'myotp_pv_mismatch' ), myotp_test_register_validate()->get_error_codes() );
check( 'register: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
$_POST['myotp_pv_phone'] = '+1 (415) 555-1234';
$errors                  = new WP_Error();
$errors->add( 'username_exists', 'taken' );
check( 'register: other error means no claim', 'verified', myotp_test_register_validate( $errors ) instanceof WP_Error ? myotp_test_vrec()['state'] : '' );
check( 'register: matching phone passes', array(), myotp_test_register_validate()->get_error_codes() );
check( 'register: validation claimed the proof', 'claiming:14155551234:' . MyOTP_PV_Session::$request_id, myotp_test_vrec()['state'] );
$saved_post              = $_POST;
$_POST['myotp_pv_phone'] = '14155550000';
myotp_test_do_action( 'register_new_user', 45 );
check( 'register: other posted phone not stamped', '', get_user_meta( 45, 'myotp_verified_phone', true ) );
$_POST = $saved_post;
myotp_test_do_action( 'register_new_user', 46 );
check( 'register: consume stamps meta', '14155551234', get_user_meta( 46, 'myotp_verified_phone', true ) );
check( 'register: record consumed by user', 'consumed:user:46', myotp_test_vrec()['state'] );
myotp_test_do_action( 'register_new_user', 47 );
check( 'register: claim is single use', '', get_user_meta( 47, 'myotp_verified_phone', true ) );
// Meta write fails: claim left unconsumed, nothing stamped.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '14155551234';
myotp_test_register_validate();
$GLOBALS['myotp_test']['meta_fail'] = true;
myotp_test_do_action( 'register_new_user', 50 );
check( 'register meta fail: not stamped', '', get_user_meta( 50, 'myotp_verified_phone', true ) );
check( 'register meta fail: claim not consumed', 'claiming:14155551234:' . MyOTP_PV_Session::$request_id, myotp_test_vrec()['state'] );
// Consume CAS loses after the stamp: stamp removed.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '14155551234';
myotp_test_register_validate();
myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'consumed:order:1' ) );
myotp_test_do_action( 'register_new_user', 51 );
check( 'register consume fail: stamp removed', '', get_user_meta( 51, 'myotp_verified_phone', true ) );
check( 'register consume fail: no rollback note when rollback worked', '', get_user_meta( 51, 'myotp_verified_phone_note', true ) );
// Rollback delete fails: value blanked as a second attempt and a note stored on the account.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '14155551234';
myotp_test_register_validate();
myotp_test_vset( array( 'phone' => '14155551234', 'at' => time() - 5, 'state' => 'consumed:order:1' ) );
$GLOBALS['myotp_test']['delete_meta_fail'] = true;
myotp_test_do_action( 'register_new_user', 52 );
check( 'register rollback fail: value blanked', '', get_user_meta( 52, 'myotp_verified_phone', true ) );
check( 'register rollback fail: note stored', true, false !== strpos( get_user_meta( 52, 'myotp_verified_phone_note', true ), 'treat as unverified' ) );
// Two registrations sharing one proof: the second fails validation.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$_POST['myotp_pv_phone'] = '14155551234';
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
check( 'checkout: unverified blocked', array( 'myotp_pv_unverified' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
MyOTP_PV_Session::set_verified( '14155551234', null );
check( 'checkout: different billing phone blocked', array( 'myotp_pv_mismatch' ), myotp_test_checkout_validate( '+1 415 555 0000' )->get_error_codes() );
check( 'checkout: mismatch did not claim', 'verified', myotp_test_vrec()['state'] );
MyOTP_PV_Session::$request_id = 'rid-A';
check( 'checkout A: matching billing phone passes', array(), myotp_test_checkout_validate( '+1 (415) 555-1234' )->get_error_codes() );
check( 'checkout A: proof claimed', 'claiming:14155551234:rid-A', myotp_test_vrec()['state'] );
MyOTP_PV_Session::$request_id = 'rid-B';
check( 'checkout B: refused at validation', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
MyOTP_PV_Session::$request_id = 'rid-A';
$wc_claimed->setValue( null, '14155551234' );
$order_a = new MyOTP_Fake_Order( 101 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_a );
check( 'checkout A: order stamped', '14155551234', $order_a->meta['_myotp_verified_phone'] );
check( 'checkout A: record consumed by order', 'consumed:order:101', myotp_test_vrec()['state'] );
check( 'checkout A: no note', array(), $order_a->notes );
$wc_claimed->setValue( null, '14155551234' );
$order_b = new MyOTP_Fake_Order( 102 );
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_b );
check( 'checkout stale: stamp written then removed', false, isset( $order_b->meta['_myotp_verified_phone'] ) );
check( 'checkout stale: saved twice (stamp, unstamp)', 2, $order_b->saved );
check( 'checkout stale: gets a note', 1, count( $order_b->notes ) );
// Rollback save throws: the second attempt blanks the value and a second note is added.
$wc_claimed->setValue( null, '14155551234' );
$order_c                   = new MyOTP_Fake_Order( 103 );
$order_c->fail_saves_after = 1; // the stamp save succeeds, the rollback save throws, the blanking save throws too
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_c );
check( 'checkout rollback fail: two notes', 2, count( $order_c->notes ) );
check( 'checkout rollback fail: second note says treat as unverified', true, false !== strpos( $order_c->notes[1], 'treat as unverified' ) );
check( 'checkout rollback fail: meta blanked in memory as second attempt', '', $order_c->meta['_myotp_verified_phone'] );
$wc_claimed->setValue( null, '14155551234' );
$order_d                   = new MyOTP_Fake_Order( 104 );
$order_d->fail_saves_after = 2; // stamp and rollback saves succeed
myotp_test_do_action( 'woocommerce_checkout_order_created', $order_d );
check( 'checkout rollback ok: stamp removed', false, isset( $order_d->meta['_myotp_verified_phone'] ) );
check( 'checkout rollback ok: one note only', 1, count( $order_d->notes ) );
check( 'checkout: third checkout on a consumed proof is told to verify again', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
// Claim must be for the posted phone: a proof for another number cannot be claimed as this one.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
MyOTP_PV_Store::$instance->before_cas = function ( $store ) {
	$store->set( 'verified_c_' . str_repeat( 'a', 32 ), myotp_pv_json( array( 'phone' => '14155559999', 'at' => time() - 1, 'state' => 'verified' ) ), 1800 );
};
check( 'checkout claim race: phone swapped, validation refuses', array( 'myotp_pv_claimed' ), myotp_test_checkout_validate( '+14155551234' )->get_error_codes() );
check( 'checkout claim race: other phone record untouched', 'verified', myotp_test_vrec()['state'] );
check( 'checkout claim race: nothing claimed for this request', '', $wc_claimed->getValue() );
// Interleaving at validation: both read "verified", both CAS, exactly one passes.
myotp_test_configure();
MyOTP_PV_Session::set_verified( '14155551234', null );
$passed                               = array();
MyOTP_PV_Store::$instance->before_cas = function () use ( &$passed ) {
	$rid                          = MyOTP_PV_Session::$request_id;
	MyOTP_PV_Session::$request_id = 'rid-other';
	$passed['other']              = myotp_test_checkout_validate( '+14155551234' )->get_error_codes();
	MyOTP_PV_Session::$request_id = $rid;
};
MyOTP_PV_Session::$request_id = 'rid-me';
$passed['me']                 = myotp_test_checkout_validate( '+14155551234' )->get_error_codes();
check( 'checkout race: interleaved request passed', array(), $passed['other'] );
check( 'checkout race: the other request refused', array( 'myotp_pv_claimed' ), $passed['me'] );
myotp_test_configure();
$GLOBALS['myotp_test']['options']['myotp_pv_options']['wc_guests_only'] = 1;
$GLOBALS['myotp_test']['logged_in']                                       = true;
check( 'checkout: guests-only skips logged-in customers', array(), myotp_test_checkout_validate( '' )->get_error_codes() );

// No unconditional writes on pending or verified rows.
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

echo "\n$count checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
