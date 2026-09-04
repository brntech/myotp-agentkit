<?php
/**
 * Per-visitor state. Counters and the pending code live in the atomic
 * store (options table). The verified record lives in the WooCommerce
 * session when one is loaded, otherwise in a transient keyed on a visitor
 * cookie, and carries a timestamp so it expires.
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

	const COOKIE       = 'myotp_pv_sid';
	const TTL          = 3600;
	const VERIFIED_TTL = 1800;
	const MAX_ATTEMPTS = 5;
	const MAX_VISITOR  = 5;
	const MAX_IP       = 10;
	const MAX_PHONE    = 3;

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
	 * Hashed client IP from REMOTE_ADDR only (proxy headers are forgeable).
	 *
	 * @return string
	 */
	public static function ip_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'ip_' . md5( wp_salt( 'nonce' ) . '|' . $ip );
	}

	/**
	 * Transient name for a verified slot.
	 *
	 * @return string
	 */
	private static function verified_name() {
		return 'myotp_pv_verified_' . md5( self::visitor_key() );
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
	 * Take a send slot on visitor, IP and destination, all or nothing.
	 *
	 * @param string $phone Destination digits.
	 * @return array{allowed: bool, retry_after: int, denied: string, taken: array}
	 */
	public static function take_send( $phone ) {
		return myotp_pv_take_send_slots(
			MyOTP_PV_Store::instance(),
			array(
				array( 'send_v_' . self::visitor_key(), self::MAX_VISITOR ),
				array( self::ip_key(), self::MAX_IP ),
				array( 'send_p_' . $phone, self::MAX_PHONE ),
			),
			time(),
			MYOTP_PV_RATE_WINDOW
		);
	}

	/**
	 * Give the slots back (provider never reached).
	 *
	 * @param array $taken Keys returned by take_send().
	 */
	public static function release_send( array $taken ) {
		foreach ( $taken as $key ) {
			myotp_pv_release_slot( MyOTP_PV_Store::instance(), $key, time(), MYOTP_PV_RATE_WINDOW );
		}
	}

	/**
	 * Key for the pending record of this visitor.
	 *
	 * @return string
	 */
	private static function pending_key() {
		return 'pending_' . self::visitor_key();
	}

	/**
	 * Store a fresh pending record with zero attempts.
	 *
	 * @param string $phone      Digits.
	 * @param string $message_id Message id, may be empty.
	 */
	public static function set_pending( $phone, $message_id ) {
		MyOTP_PV_Store::instance()->set(
			self::pending_key(),
			myotp_pv_json(
				array(
					'phone'      => $phone,
					'message_id' => (string) $message_id,
					'attempts'   => 0,
					'sent_at'    => time(),
					'ttl'        => self::TTL,
				)
			),
			self::TTL
		);
	}

	/**
	 * Reserve one verify attempt.
	 *
	 * @return array{ok: bool, locked: bool, pending: array|null, attempts: int}
	 */
	public static function reserve_attempt() {
		return myotp_pv_reserve_attempt( MyOTP_PV_Store::instance(), self::pending_key(), self::MAX_ATTEMPTS );
	}

	/**
	 * Forget the pending record.
	 */
	public static function clear_pending() {
		MyOTP_PV_Store::instance()->delete( self::pending_key() );
	}

	/**
	 * Mark the phone verified now.
	 *
	 * @param string $phone Digits.
	 */
	public static function set_verified( $phone ) {
		$record = array(
			'phone' => $phone,
			'at'    => time(),
		);
		if ( self::use_wc() ) {
			WC()->session->set( 'myotp_pv_verified', $record );
			return;
		}
		set_transient( self::verified_name(), $record, self::VERIFIED_TTL );
	}

	/**
	 * The verified phone for this visitor, or empty when none or expired.
	 *
	 * @return string
	 */
	public static function verified_phone() {
		if ( self::use_wc() ) {
			$record = WC()->session->get( 'myotp_pv_verified' );
		} else {
			$record = get_transient( self::verified_name() );
		}
		return myotp_pv_verified_phone_from( $record, time(), self::VERIFIED_TTL );
	}

	/**
	 * Consume the verified record (after it was used for an order or a registration).
	 */
	public static function clear_verified() {
		if ( self::use_wc() ) {
			WC()->session->set( 'myotp_pv_verified', null );
			return;
		}
		delete_transient( self::verified_name() );
	}
}
