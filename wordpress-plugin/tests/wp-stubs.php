<?php
/**
 * Minimal WordPress fakes so tests/run.php can load the plugin, boot it
 * through the hooks it registers, and call handlers through those hooks.
 * add_action / add_filter record into a registry; myotp_test_do_action()
 * and apply_filters() run what was registered, so an unwired hook fails.
 * Escaping functions are identity functions on purpose: the tests check
 * behaviour, not markup.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );

/** Thrown by wp_send_json_* to end a handler the way exit would. */
class MyOTP_Test_Exit extends Exception {
	public $success;
	public $data;
	public $status;
	public function __construct( $success, $data, $status ) {
		parent::__construct( 'json exit' );
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}

/** In-memory store with the same contract as MyOTP_PV_Store. $before_cas runs once before a cas, to inject a race. */
class MyOTP_Mem_Store {
	public $rows       = array();
	public $before_cas = null;
	public $cas_calls  = 0;
	public $deletes    = array();
	public function get( $key ) {
		return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : null;
	}
	public function add( $key, $raw, $ttl ) {
		if ( isset( $this->rows[ $key ] ) ) {
			return false;
		}
		$this->rows[ $key ] = $raw;
		return true;
	}
	public function cas( $key, $expected, $raw, $ttl ) {
		$this->cas_calls++;
		if ( $this->before_cas ) {
			$hook             = $this->before_cas;
			$this->before_cas = null;
			$hook( $this );
		}
		if ( ! isset( $this->rows[ $key ] ) || $this->rows[ $key ] !== $expected ) {
			return false;
		}
		$this->rows[ $key ] = $raw;
		return true;
	}
	public function set( $key, $raw, $ttl ) {
		$this->rows[ $key ] = $raw;
	}
	public function delete( $key, $expected_raw = null ) {
		$this->deletes[] = array( $key, $expected_raw );
		if ( ! isset( $this->rows[ $key ] ) ) {
			return false;
		}
		if ( null !== $expected_raw && $this->rows[ $key ] !== $expected_raw ) {
			return false;
		}
		unset( $this->rows[ $key ] );
		return true;
	}
	public $sweep_full = false;
	public function sweep_expired( $batch = 1000, $max_batches = 20 ) {
		return array(
			'removed' => 0,
			'full'    => $this->sweep_full,
		);
	}
}

class WP_Error {
	public $errors = array();
	public function add( $code, $message ) {
		$this->errors[ $code ] = $message;
	}
	public function get_error_codes() {
		return array_keys( $this->errors );
	}
}

class WP_Post {
	public $post_content = '';
}

/** Presence of this class makes the plugin register its WooCommerce hooks. */
class WooCommerce {}

/** WC() with no session, so the plugin falls back to the cookie visitor id. */
function WC() {
	static $wc = null;
	if ( null === $wc ) {
		$wc          = new stdClass();
		$wc->session = null;
	}
	return $wc;
}

class MyOTP_Fake_Order {
	public $id;
	public $meta  = array();
	public $notes = array();
	public $saved = 0;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	public function delete_meta_data( $k ) {
		unset( $this->meta[ $k ] );
	}
	public function save() {
		$this->saved++;
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
}

$GLOBALS['myotp_test'] = array(
	'options'    => array(),
	'transients' => array(),
	'user_meta'  => array(),
	'http_queue' => array(),
	'http_log'   => array(),
	'nonce_ok'   => true,
	'can_manage' => false,
	'logged_in'  => false,
	'privacy'    => null,
	'hooks'      => array(),
	'cron'       => array(),
);

function myotp_test_reset() {
	$GLOBALS['myotp_test']['options']    = array();
	$GLOBALS['myotp_test']['transients'] = array();
	$GLOBALS['myotp_test']['user_meta']  = array();
	$GLOBALS['myotp_test']['http_queue'] = array();
	$GLOBALS['myotp_test']['http_log']   = array();
	$GLOBALS['myotp_test']['nonce_ok']   = true;
	$GLOBALS['myotp_test']['can_manage'] = false;
	$GLOBALS['myotp_test']['logged_in']  = false;
	$_POST                                = array();
	$_COOKIE                              = array();
	$_SERVER['REMOTE_ADDR']               = '203.0.113.5';
	$GLOBALS['myotp_test']['http_before'] = null;
	$GLOBALS['myotp_test']['single']      = array();
	$GLOBALS['myotp_test']['meta_fail']   = false;
	MyOTP_PV_Store::$instance             = new MyOTP_Mem_Store();
	MyOTP_PV_Session::$request_id         = 'rid-' . bin2hex( random_bytes( 4 ) );
}

/** Queue a canned HTTP answer: array( code, body-array|string ) or 'wp_error'. */
function myotp_test_http( $code, $body ) {
	$GLOBALS['myotp_test']['http_queue'][] = array( $code, $body );
}

// Hook registry.
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['myotp_test']['hooks'][ $hook ][] = array( $cb, $prio, $args );
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	add_action( $hook, $cb, $prio, $args );
}
function myotp_test_has_hook( string $hook, $cb = null ): bool {
	if ( empty( $GLOBALS['myotp_test']['hooks'][ $hook ] ) ) {
		return false;
	}
	if ( null === $cb ) {
		return true;
	}
	foreach ( $GLOBALS['myotp_test']['hooks'][ $hook ] as $entry ) {
		if ( $entry[0] === $cb ) {
			return true;
		}
	}
	return false;
}
/** Run every callable registered on $hook, in priority order, with $args. */
function myotp_test_do_action( string $hook, ...$args ) {
	if ( empty( $GLOBALS['myotp_test']['hooks'][ $hook ] ) ) {
		throw new RuntimeException( "no callable registered on hook $hook" );
	}
	$entries = $GLOBALS['myotp_test']['hooks'][ $hook ];
	usort( $entries, function ( $a, $b ) { return $a[1] <=> $b[1]; } );
	foreach ( $entries as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, max( 1, (int) $entry[2] ) ) );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['myotp_test']['hooks'][ $hook ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['myotp_test']['hooks'][ $hook ] as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, (int) $entry[2] - 1 ) ) ) );
	}
	return $value;
}
function add_shortcode( $tag, $cb ) {
	$GLOBALS['myotp_test']['hooks'][ 'shortcode:' . $tag ][] = array( $cb, 10, 1 );
}
function register_activation_hook( $file, $cb ) {
	$GLOBALS['myotp_test']['hooks']['activate'][] = array( $cb, 10, 0 );
}
function register_deactivation_hook( $file, $cb ) {
	$GLOBALS['myotp_test']['hooks']['deactivate'][] = array( $cb, 10, 0 );
}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/myotp-phone-verification/'; }
function plugin_basename( $file ) { return 'myotp-phone-verification/myotp-phone-verification.php'; }
function load_plugin_textdomain( $d, $a, $b ) {}
function get_bloginfo( $k ) { return '6.6'; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['myotp_test']['settings'][ $name ] = $args; }

// Cron.
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['myotp_test']['cron'][ $hook ] ) ? $GLOBALS['myotp_test']['cron'][ $hook ] : false; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['myotp_test']['cron'][ $hook ] = array( $ts, $recurrence ); return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['myotp_test']['cron'][ $hook ] ); return 1; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['myotp_test']['single'][] = array( $ts, $hook ); return true; }

// Options, transients, meta.
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['myotp_test']['options'] ) ? $GLOBALS['myotp_test']['options'][ $name ] : $default;
}
function add_option( $name, $value ) { $GLOBALS['myotp_test']['options'][ $name ] = $value; return true; }
function update_option( $name, $value ) { $GLOBALS['myotp_test']['options'][ $name ] = $value; return true; }
function get_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['myotp_test']['transients'] ) ? $GLOBALS['myotp_test']['transients'][ $name ] : false;
}
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['myotp_test']['transients'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['myotp_test']['transients'][ $name ] ); return true; }
function update_user_meta( $uid, $key, $value ) {
	if ( ! empty( $GLOBALS['myotp_test']['meta_fail'] ) ) {
		return false;
	}
	$GLOBALS['myotp_test']['user_meta'][ $uid ][ $key ] = $value;
	return true;
}
function delete_user_meta( $uid, $key ) { unset( $GLOBALS['myotp_test']['user_meta'][ $uid ][ $key ] ); return true; }
function get_user_meta( $uid, $key, $single = false ) {
	return isset( $GLOBALS['myotp_test']['user_meta'][ $uid ][ $key ] ) ? $GLOBALS['myotp_test']['user_meta'][ $uid ][ $key ] : '';
}

// i18n and escaping (identity).
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html_e( $t, $d = null ) { echo $t; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function esc_url( $t ) { return $t; }
function wp_kses_post( $t ) { return $t; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }

// Auth.
function check_ajax_referer( $action, $field ) {
	if ( ! $GLOBALS['myotp_test']['nonce_ok'] ) {
		throw new MyOTP_Test_Exit( false, array( 'message' => 'bad nonce' ), 403 );
	}
	return 1;
}
function current_user_can( $cap ) { return $GLOBALS['myotp_test']['can_manage']; }
function is_user_logged_in() { return $GLOBALS['myotp_test']['logged_in']; }
function get_current_user_id() { return $GLOBALS['myotp_test']['logged_in'] ? 7 : 0; }
function wp_generate_password( $len, $special ) { return bin2hex( random_bytes( (int) ( $len / 2 ) ) ); }
function wp_salt( $scheme ) { return 'test-salt'; }
function is_ssl() { return false; }
function wp_add_privacy_policy_content( $name, $content ) { $GLOBALS['myotp_test']['privacy'] = array( $name, $content ); }

// JSON responses end the handler.
function wp_send_json_success( $data = null, $status = 200 ) { throw new MyOTP_Test_Exit( true, $data, $status ); }
function wp_send_json_error( $data = null, $status = 200 ) { throw new MyOTP_Test_Exit( false, $data, $status ); }

// HTTP.
function wp_remote_post( $url, $args ) {
	$GLOBALS['myotp_test']['http_log'][] = array( 'url' => $url, 'args' => $args );
	// A one-shot hook that runs while the "provider call" is in flight, to interleave another request.
	if ( ! empty( $GLOBALS['myotp_test']['http_before'] ) ) {
		$hook                                = $GLOBALS['myotp_test']['http_before'];
		$GLOBALS['myotp_test']['http_before'] = null;
		$hook();
	}
	$next = array_shift( $GLOBALS['myotp_test']['http_queue'] );
	if ( null === $next ) {
		throw new RuntimeException( 'no canned HTTP answer queued' );
	}
	if ( 'wp_error' === $next[0] ) {
		return new stdClass();
	}
	return array(
		'code' => $next[0],
		'body' => is_string( $next[1] ) ? $next[1] : json_encode( $next[1] ),
	);
}
function is_wp_error( $r ) { return $r instanceof stdClass; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

/** Run an AJAX hook through the registry and return the MyOTP_Test_Exit it ended with. */
function myotp_test_ajax( string $hook ): MyOTP_Test_Exit {
	try {
		myotp_test_do_action( $hook );
	} catch ( MyOTP_Test_Exit $e ) {
		return $e;
	}
	throw new RuntimeException( "hook $hook returned without sending JSON" );
}
