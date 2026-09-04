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
 * Sliding-window rate limit. Returns the pruned timestamp list and whether
 * one more send is allowed right now. Does not append the new send; the
 * caller appends $now after a successful send.
 *
 * @param array $timestamps Unix timestamps of earlier sends.
 * @param int   $now        Current unix time.
 * @param int   $max        Max sends in the window.
 * @param int   $window     Window length in seconds.
 * @return array{allowed: bool, timestamps: array, retry_after: int}
 */
function myotp_pv_rate_limit( $timestamps, $now, $max = 5, $window = 600 ) {
	$kept = array();
	foreach ( (array) $timestamps as $ts ) {
		$ts = (int) $ts;
		if ( $ts > $now - $window && $ts <= $now ) {
			$kept[] = $ts;
		}
	}
	sort( $kept );
	$allowed     = count( $kept ) < $max;
	$retry_after = 0;
	if ( ! $allowed ) {
		$retry_after = max( 1, ( $kept[0] + $window ) - $now );
	}
	return array(
		'allowed'     => $allowed,
		'timestamps'  => $kept,
		'retry_after' => $retry_after,
	);
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
