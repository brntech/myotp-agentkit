<?php
/**
 * Per-visitor state: rate limit window, pending message id, verified number.
 * Uses the WooCommerce session when it is loaded, otherwise transients keyed
 * on a visitor cookie.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-visitor state storage.
 */
class MyOTP_PV_Session {

	const COOKIE = 'myotp_pv_sid';
	const TTL    = 3600;

	/**
	 * Stable visitor id: WC customer id, user id, or a random cookie.
	 *
	 * @return string
	 */
	public static function visitor_key() {
		if ( self::use_wc() && method_exists( WC()->session, 'get_customer_id' ) ) {
			$id = (string) WC()->session->get_customer_id();
			if ( '' !== $id ) {
				return 'wc_' . $id;
			}
		}
		if ( is_user_logged_in() ) {
			return 'u_' . get_current_user_id();
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$sid = preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
			if ( 32 === strlen( $sid ) ) {
				return 'c_' . $sid;
			}
		}
		$sid = md5( wp_generate_password( 32, false ) . microtime( true ) );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $sid, time() + DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE[ self::COOKIE ] = $sid;
		return 'c_' . $sid;
	}

	/**
	 * Transient name for a state slot.
	 *
	 * @param string $slot Slot name.
	 * @return string
	 */
	private static function name( $slot ) {
		return 'myotp_pv_' . $slot . '_' . md5( self::visitor_key() );
	}

	/**
	 * Read a slot.
	 *
	 * @param string $slot Slot name.
	 * @return mixed
	 */
	public static function get( $slot ) {
		if ( self::use_wc() ) {
			return WC()->session->get( 'myotp_pv_' . $slot );
		}
		$v = get_transient( self::name( $slot ) );
		return false === $v ? null : $v;
	}

	/**
	 * Write a slot.
	 *
	 * @param string $slot  Slot name.
	 * @param mixed  $value Value.
	 */
	public static function set( $slot, $value ) {
		if ( self::use_wc() ) {
			WC()->session->set( 'myotp_pv_' . $slot, $value );
			return;
		}
		set_transient( self::name( $slot ), $value, self::TTL );
	}

	/**
	 * Delete a slot.
	 *
	 * @param string $slot Slot name.
	 */
	public static function delete( $slot ) {
		if ( self::use_wc() ) {
			WC()->session->set( 'myotp_pv_' . $slot, null );
			return;
		}
		delete_transient( self::name( $slot ) );
	}

	/**
	 * True when a WooCommerce session object is available.
	 *
	 * @return bool
	 */
	private static function use_wc() {
		return function_exists( 'WC' ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' );
	}

	/**
	 * Rate-limit check for the current visitor. Records the send when allowed.
	 *
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function consume_send() {
		$now    = time();
		$result = myotp_pv_rate_limit( (array) self::get( 'sends' ), $now, MYOTP_PV_RATE_MAX, MYOTP_PV_RATE_WINDOW );
		if ( $result['allowed'] ) {
			$stamps   = $result['timestamps'];
			$stamps[] = $now;
			self::set( 'sends', $stamps );
		}
		return array(
			'allowed'     => $result['allowed'],
			'retry_after' => $result['retry_after'],
		);
	}

	/**
	 * The verified phone number for this visitor, or empty string.
	 *
	 * @return string
	 */
	public static function verified_phone() {
		$phone = self::get( 'verified' );
		return is_string( $phone ) ? $phone : '';
	}
}
