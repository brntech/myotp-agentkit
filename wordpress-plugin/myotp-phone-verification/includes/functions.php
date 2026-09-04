<?php
/**
 * Pure helper functions. No WordPress dependencies so tests/run.php can
 * load this file in plain PHP.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'MYOTP_PV_KEY_MASK' ) ) {
	define( 'MYOTP_PV_KEY_MASK', '********' );
}

/**
 * Normalise a phone number to digits only, E.164 order, no plus sign.
 * Strips +, spaces, dashes, dots, brackets. Does not strip leading zeros.
 *
 * @param mixed $raw Raw input.
 * @return string Digits, or empty string.
 */
function myotp_pv_normalize_phone( $raw ) {
	if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
		return '';
	}
	return preg_replace( '/[^0-9]/', '', (string) $raw );
}

/**
 * True when the number matches the API pattern ^[1-9][0-9]{6,14}$.
 *
 * @param string $digits Normalised number.
 * @return bool
 */
function myotp_pv_is_valid_phone( $digits ) {
	return is_string( $digits ) && 1 === preg_match( '/^[1-9][0-9]{6,14}$/', $digits );
}

/**
 * True when the OTP matches ^[0-9]{3,8}$.
 *
 * @param string $otp Code as typed.
 * @return bool
 */
function myotp_pv_is_valid_otp( $otp ) {
	return is_string( $otp ) && 1 === preg_match( '/^[0-9]{3,8}$/', $otp );
}

/**
 * Stable JSON encoding without WordPress. Keys are sorted so two encodes of
 * the same data compare equal byte for byte.
 *
 * @param array $data Data.
 * @return string
 */
function myotp_pv_json( array $data ) {
	ksort( $data );
	return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

/**
 * Atomic fixed-window counter over a store. The store is any object with
 * get( $key ) : string|null, add( $key, $raw, $ttl ) : bool (only when
 * absent), cas( $key, $expected_raw, $new_raw, $ttl ) : bool (only when the
 * stored raw value still equals $expected_raw) and delete( $key ).
 * Values are JSON strings so cas compares bytes.
 *
 * @param object $store  Store.
 * @param string $key    Counter key.
 * @param int    $now    Unix time.
 * @param int    $max    Max takes per window.
 * @param int    $window Window length in seconds.
 * @return array{allowed: bool, count: int, retry_after: int}
 */
function myotp_pv_take_slot( $store, $key, $now, $max, $window ) {
	for ( $try = 0; $try < 8; $try++ ) {
		$raw   = $store->get( $key );
		$fresh = myotp_pv_json(
			array(
				'c' => 1,
				's' => $now,
			)
		);
		if ( null === $raw ) {
			if ( $store->add( $key, $fresh, $window ) ) {
				return array(
					'allowed'     => true,
					'count'       => 1,
					'retry_after' => 0,
				);
			}
			continue;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['c'], $data['s'] ) || (int) $data['s'] + $window <= $now ) {
			if ( $store->cas( $key, $raw, $fresh, $window ) ) {
				return array(
					'allowed'     => true,
					'count'       => 1,
					'retry_after' => 0,
				);
			}
			continue;
		}
		if ( (int) $data['c'] >= $max ) {
			return array(
				'allowed'     => false,
				'count'       => (int) $data['c'],
				'retry_after' => max( 1, (int) $data['s'] + $window - $now ),
			);
		}
		$next = myotp_pv_json(
			array(
				'c' => (int) $data['c'] + 1,
				's' => (int) $data['s'],
			)
		);
		if ( $store->cas( $key, $raw, $next, $window ) ) {
			return array(
				'allowed'     => true,
				'count'       => (int) $data['c'] + 1,
				'retry_after' => 0,
			);
		}
	}
	return array(
		'allowed'     => false,
		'count'       => $max,
		'retry_after' => $window,
	);
}

/**
 * Give back one take on a counter (used when the provider call never left
 * the server). Never goes below zero, never touches an expired window.
 *
 * @param object $store  Store.
 * @param string $key    Counter key.
 * @param int    $now    Unix time.
 * @param int    $window Window length in seconds.
 * @return bool True when a take was released.
 */
function myotp_pv_release_slot( $store, $key, $now, $window ) {
	for ( $try = 0; $try < 8; $try++ ) {
		$raw = $store->get( $key );
		if ( null === $raw ) {
			return false;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['c'], $data['s'] ) || (int) $data['s'] + $window <= $now || (int) $data['c'] <= 0 ) {
			return false;
		}
		$next = myotp_pv_json(
			array(
				'c' => (int) $data['c'] - 1,
				's' => (int) $data['s'],
			)
		);
		if ( $store->cas( $key, $raw, $next, $window ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Take one slot on every dimension, best effort all or nothing. $dims is a
 * list of array( key, max ) or array( key, max, window ). When any
 * dimension denies, the ones already taken are released so a denied
 * request costs nothing. Sequential, not a transaction: under contention
 * another request can briefly see a partial take.
 *
 * @param object $store  Store.
 * @param array  $dims   List of array( key, max[, window] ).
 * @param int    $now    Unix time.
 * @param int    $window Default window seconds.
 * @return array{allowed: bool, retry_after: int, denied: string, taken: array}
 */
function myotp_pv_take_send_slots( $store, array $dims, $now, $window ) {
	$taken = array();
	foreach ( $dims as $dim ) {
		$w = isset( $dim[2] ) ? (int) $dim[2] : $window;
		$r = myotp_pv_take_slot( $store, $dim[0], $now, (int) $dim[1], $w );
		if ( ! $r['allowed'] ) {
			foreach ( $taken as $k ) {
				myotp_pv_release_slot( $store, $k[0], $now, $k[1] );
			}
			return array(
				'allowed'     => false,
				'retry_after' => $r['retry_after'],
				'denied'      => $dim[0],
				'taken'       => array(),
			);
		}
		$taken[] = array( $dim[0], $w );
	}
	return array(
		'allowed'     => true,
		'retry_after' => 0,
		'denied'      => '',
		'taken'       => $taken,
	);
}

/**
 * Atomically reserve one verification attempt on a pending record. The
 * record is JSON with phone, message_id, attempts and exp (absolute unix
 * expiry). When $max attempts are already used the record is left alone
 * and locked is true; the caller writes the phone lock. Returns the raw
 * value written so later transitions can be guarded by it.
 *
 * @param object $store Store.
 * @param string $key   Pending key.
 * @param int    $max   Max attempts.
 * @param int    $now   Unix time.
 * @return array{ok: bool, locked: bool, pending: array|null, attempts: int, raw: string|null}
 */
function myotp_pv_reserve_attempt( $store, $key, $max, $now ) {
	$none = array(
		'ok'       => false,
		'locked'   => false,
		'pending'  => null,
		'attempts' => 0,
		'raw'      => null,
	);
	for ( $try = 0; $try < 8; $try++ ) {
		$raw = $store->get( $key );
		if ( null === $raw ) {
			return $none;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['phone'] ) ) {
			$store->delete( $key, $raw );
			return $none;
		}
		$attempts = isset( $data['attempts'] ) ? (int) $data['attempts'] : 0;
		if ( $attempts >= $max ) {
			return array(
				'ok'       => false,
				'locked'   => true,
				'pending'  => $data,
				'attempts' => $attempts,
				'raw'      => $raw,
			);
		}
		$data['attempts'] = $attempts + 1;
		$next             = myotp_pv_json( $data );
		if ( $store->cas( $key, $raw, $next, myotp_pv_ttl_left( $data, $now ) ) ) {
			return array(
				'ok'       => true,
				'locked'   => false,
				'pending'  => $data,
				'attempts' => $attempts + 1,
				'raw'      => $next,
			);
		}
	}
	return $none;
}

/**
 * Seconds until a record's absolute expiry, at least 1.
 *
 * @param array $data Record with exp (unix time) or ttl (seconds).
 * @param int   $now  Unix time.
 * @return int
 */
function myotp_pv_ttl_left( array $data, $now ) {
	if ( isset( $data['exp'] ) ) {
		return max( 1, (int) $data['exp'] - (int) $now );
	}
	return isset( $data['ttl'] ) ? max( 1, (int) $data['ttl'] ) : 3600;
}

/**
 * Write a phone lock (5 wrong codes) atomically. add() only, so a lock
 * cannot be shortened by a later writer. Returns true when this call
 * created it.
 *
 * @param object $store Store.
 * @param string $key   Lock key.
 * @param int    $now   Unix time.
 * @param int    $ttl   Lock seconds.
 * @return bool
 */
function myotp_pv_lock_phone( $store, $key, $now, $ttl ) {
	return $store->add(
		$key,
		myotp_pv_json(
			array(
				'at'    => (int) $now,
				'until' => (int) $now + (int) $ttl,
			)
		),
		$ttl
	);
}

/**
 * Seconds left on a phone lock, 0 when none.
 *
 * @param object $store Store.
 * @param string $key   Lock key.
 * @param int    $now   Unix time.
 * @return int
 */
function myotp_pv_lock_remaining( $store, $key, $now ) {
	$raw = $store->get( $key );
	if ( null === $raw ) {
		return 0;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || ! isset( $data['until'] ) ) {
		return 0;
	}
	return max( 0, (int) $data['until'] - (int) $now );
}

/**
 * Give back one reserved attempt (the provider was never reached). Floors
 * at zero and leaves a missing record alone.
 *
 * @param object $store Store.
 * @param string $key   Pending key.
 * @return bool
 */
function myotp_pv_release_attempt( $store, $key ) {
	for ( $try = 0; $try < 8; $try++ ) {
		$raw = $store->get( $key );
		if ( null === $raw ) {
			return false;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['attempts'] ) ) {
			return false;
		}
		$data['attempts'] = (int) $data['attempts'] - 1;
		if ( $store->cas( $key, $raw, myotp_pv_json( $data ), myotp_pv_ttl_left( $data, time() ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Atomically move a verified record from one state to another. The record
 * must be unexpired, in exactly $from, and (when $phone is given) hold
 * exactly that phone as re-read at CAS time. Returns the phone when this
 * caller won the CAS, empty when missing, expired, in another state, for
 * another phone, or lost the race.
 *
 * @param object $store Store.
 * @param string $key   Verified key.
 * @param string $from  Required current state, e.g. verified.
 * @param string $to    New state, e.g. claiming:<phone>:<rid> or consumed:order:<id>.
 * @param int    $now   Unix time.
 * @param int    $ttl   Seconds a verification stays valid from its timestamp.
 * @param string $phone Phone the record must hold, or empty to skip the check.
 * @return string
 */
function myotp_pv_transition_verified( $store, $key, $from, $to, $now, $ttl = 1800, $phone = '' ) {
	for ( $try = 0; $try < 8; $try++ ) {
		$raw = $store->get( $key );
		if ( null === $raw ) {
			return '';
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['phone'] ) || ! isset( $data['at'] ) ) {
			return '';
		}
		$state = isset( $data['state'] ) ? (string) $data['state'] : 'verified';
		if ( $state !== $from || (int) $data['at'] + $ttl <= $now || (int) $data['at'] > $now ) {
			return '';
		}
		if ( '' !== $phone && (string) $data['phone'] !== (string) $phone ) {
			return '';
		}
		$data['state'] = $to;
		if ( $store->cas( $key, $raw, myotp_pv_json( $data ), max( 60, (int) $data['at'] + $ttl - $now ) ) ) {
			return (string) $data['phone'];
		}
	}
	return '';
}

/**
 * Claim a verified record: verified -> claiming:<phone>:<rid>.
 *
 * @param object $store Store.
 * @param string $key   Verified key.
 * @param string $phone Phone that passed validation.
 * @param string $rid   Per-request id.
 * @param int    $now   Unix time.
 * @param int    $ttl   Validity seconds.
 * @return string Phone when won, empty otherwise.
 */
function myotp_pv_claim_verified( $store, $key, $phone, $rid, $now, $ttl = 1800 ) {
	return myotp_pv_transition_verified( $store, $key, 'verified', 'claiming:' . $phone . ':' . $rid, $now, $ttl, $phone );
}

/**
 * Consume a claimed record: claiming:<phone>:<rid> -> consumed:<tag>.
 *
 * @param object $store Store.
 * @param string $key   Verified key.
 * @param string $phone Phone in the claim.
 * @param string $rid   Per-request id used in the claim.
 * @param string $tag   Consumer, e.g. order:123 or user:7.
 * @param int    $now   Unix time.
 * @param int    $ttl   Validity seconds.
 * @return string Phone when won, empty otherwise.
 */
function myotp_pv_consume_claim( $store, $key, $phone, $rid, $tag, $now, $ttl = 1800 ) {
	return myotp_pv_transition_verified( $store, $key, 'claiming:' . $phone . ':' . $rid, 'consumed:' . $tag, $now, $ttl, $phone );
}

/**
 * The phone in a verified record, or empty when missing, consumed, or older than $ttl.
 *
 * @param mixed $record Array with phone and at, or anything else.
 * @param int   $now    Unix time.
 * @param int   $ttl    Seconds a verification stays valid.
 * @return string
 */
function myotp_pv_verified_phone_from( $record, $now, $ttl = 1800 ) {
	if ( ! is_array( $record ) || empty( $record['phone'] ) || ! isset( $record['at'] ) ) {
		return '';
	}
	if ( isset( $record['state'] ) && 'verified' !== $record['state'] ) {
		return '';
	}
	if ( (int) $record['at'] + $ttl <= $now || (int) $record['at'] > $now ) {
		return '';
	}
	return (string) $record['phone'];
}

/**
 * True when a raw verified record may be replaced by a fresh verification:
 * absent, or in state "verified" (fresh or stale). A record that a checkout
 * or registration has claimed or consumed must never be overwritten.
 *
 * @param string|null $raw Raw verified value read earlier.
 * @return bool
 */
function myotp_pv_verified_replaceable( $raw ) {
	if ( null === $raw ) {
		return true;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return true;
	}
	$state = isset( $data['state'] ) ? (string) $data['state'] : 'verified';
	return 'verified' === $state;
}

/**
 * Install a record guarded by what the caller read earlier: add() when
 * it read nothing, cas() from that raw value otherwise. Returns the raw
 * value written, or null when another writer got there first.
 *
 * @param object      $store    Store.
 * @param string      $key      Key.
 * @param string|null $expected Raw value read earlier, or null for absent.
 * @param array       $data     New record.
 * @param int         $ttl      Seconds.
 * @return string|null
 */
function myotp_pv_install( $store, $key, $expected, array $data, $ttl ) {
	$raw = myotp_pv_json( $data );
	if ( null === $expected ) {
		return $store->add( $key, $raw, $ttl ) ? $raw : null;
	}
	return $store->cas( $key, $expected, $raw, $ttl ) ? $raw : null;
}

/**
 * True when a /generate_otp 2xx body has the shape we rely on.
 *
 * @param mixed $body Decoded body.
 * @return bool
 */
function myotp_pv_is_send_body( $body ) {
	return is_array( $body ) && isset( $body['message_id'] ) && is_scalar( $body['message_id'] ) && '' !== (string) $body['message_id'];
}

/**
 * Default option values.
 *
 * @return array
 */
function myotp_pv_default_options() {
	return array(
		'api_key'          => '',
		'channel'          => 'sms',
		'otp_length'       => 6,
		'otp_validity'     => 300,
		'brand'            => '',
		'wc_enabled'       => 1,
		'wc_guests_only'   => 0,
		'register_enabled' => 0,
		'site_hourly_cap'  => 100,
	);
}

/**
 * Sanitise submitted settings. $current is the stored value, used to keep
 * the API key when the masked placeholder is submitted back.
 *
 * @param mixed $input   Submitted array.
 * @param array $current Currently stored options.
 * @return array
 */
function myotp_pv_sanitize_options( $input, $current = array() ) {
	$defaults = myotp_pv_default_options();
	$current  = array_merge( $defaults, is_array( $current ) ? $current : array() );
	$input    = is_array( $input ) ? $input : array();
	$out      = $current;

	if ( array_key_exists( 'api_key', $input ) ) {
		$key = trim( (string) $input['api_key'] );
		if ( '' === $key ) {
			$out['api_key'] = '';
		} elseif ( MYOTP_PV_KEY_MASK !== $key && ! preg_match( '/^\*+$/', $key ) ) {
			$out['api_key'] = preg_match( '/^[a-zA-Z0-9_-]{32}$/', $key ) ? $key : $current['api_key'];
		}
	}

	if ( isset( $input['channel'] ) ) {
		$channel        = strtolower( (string) $input['channel'] );
		$out['channel'] = in_array( $channel, array( 'sms', 'whatsapp', 'telegram' ), true ) ? $channel : 'sms';
	}

	if ( isset( $input['otp_length'] ) ) {
		$len               = (int) $input['otp_length'];
		$out['otp_length'] = ( $len >= 4 && $len <= 8 ) ? $len : 6;
	}

	if ( isset( $input['otp_validity'] ) ) {
		$val                 = (int) $input['otp_validity'];
		$out['otp_validity'] = ( $val >= 60 && $val <= 86400 ) ? $val : 300;
	}

	if ( array_key_exists( 'brand', $input ) ) {
		$brand        = trim( (string) $input['brand'] );
		$out['brand'] = ( '' === $brand || preg_match( '/^[a-zA-Z0-9.]{3,16}$/', $brand ) ) ? $brand : $current['brand'];
	}

	// Checkboxes are absent when unticked, so only reset them when a form was actually submitted.
	if ( ! empty( $input ) ) {
		if ( isset( $input['site_hourly_cap'] ) ) {
			$cap                    = (int) $input['site_hourly_cap'];
			$out['site_hourly_cap'] = ( $cap >= 1 && $cap <= 100000 ) ? $cap : $current['site_hourly_cap'];
		}

		foreach ( array( 'wc_enabled', 'wc_guests_only', 'register_enabled' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}
	}

	return $out;
}

/**
 * Mask an API key for display: first 4 chars then stars, or the plain mask.
 *
 * @param string $key Stored key.
 * @return string
 */
function myotp_pv_mask_key( $key ) {
	$key = (string) $key;
	if ( '' === $key ) {
		return '';
	}
	return MYOTP_PV_KEY_MASK;
}

/**
 * Turn a decoded API response into a message a visitor can read.
 * Handles the {"error": {"http_code": N, "message": "..."}} envelope and
 * the flat {"status": "...", "message": "..."} shape.
 *
 * @param mixed  $body     Decoded JSON (array) or null.
 * @param int    $http     HTTP status code.
 * @param string $fallback Text when nothing usable is found.
 * @return string
 */
function myotp_pv_error_message( $body, $http, $fallback = 'The verification service returned an error.' ) {
	if ( is_array( $body ) ) {
		if ( isset( $body['error'] ) && is_array( $body['error'] ) && ! empty( $body['error']['message'] ) ) {
			return (string) $body['error']['message'];
		}
		if ( isset( $body['error'] ) && is_string( $body['error'] ) && '' !== $body['error'] ) {
			return $body['error'];
		}
		if ( ! empty( $body['message'] ) && is_string( $body['message'] ) ) {
			return $body['message'];
		}
	}
	if ( 401 === (int) $http ) {
		return 'The site\'s MyOTP API key was rejected. Ask the site owner to check it.';
	}
	if ( 402 === (int) $http ) {
		return 'The site\'s MyOTP balance is too low to send a code.';
	}
	return $fallback;
}
