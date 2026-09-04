<?php
/**
 * Per-visitor state. Counters, the pending code and the verified record
 * all live in the atomic store (options table), so every transition is a
 * compare-and-swap. The WooCommerce customer id or the user id names the
 * visitor when available, otherwise a random cookie does.
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
	const SITE_WINDOW  = 3600;

	/**
	 * Stable visitor id: WC customer id, user id, or a random cookie.
	 *
	 * @return string
	 */
	public static function visitor_key() {
		if ( function_exists( 'WC' ) && is_object( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) ) {
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
	 * Client IP. REMOTE_ADDR only; forwarding headers are forgeable. Hosts
	 * behind a trusted reverse proxy can supply the real address through
	 * the myotp_pv_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'myotp_pv_client_ip', $ip );
	}

	/**
	 * Hashed client IP counter key.
	 *
	 * @return string
	 */
	public static function ip_key() {
		return 'ip_' . md5( wp_salt( 'nonce' ) . '|' . self::client_ip() );
	}

	/**
	 * Site-wide sends per hour. Setting, then the myotp_pv_site_hourly_cap filter.
	 *
	 * @return int
	 */
	public static function site_hourly_cap() {
		$o   = myotp_pv_get_options();
		$cap = isset( $o['site_hourly_cap'] ) ? (int) $o['site_hourly_cap'] : 100;
		return max( 1, (int) apply_filters( 'myotp_pv_site_hourly_cap', $cap ) );
	}

	/**
	 * Take a send slot on visitor, IP, destination and the whole site.
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
				array( 'send_site', self::site_hourly_cap(), self::SITE_WINDOW ),
			),
			time(),
			MYOTP_PV_RATE_WINDOW
		);
	}

	/**
	 * Give the slots back (provider never reached).
	 *
	 * @param array $taken Pairs of key and window returned by take_send().
	 */
	public static function release_send( array $taken ) {
		foreach ( $taken as $pair ) {
			myotp_pv_release_slot( MyOTP_PV_Store::instance(), $pair[0], time(), (int) $pair[1] );
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
	 * Key for the verified record of this visitor.
	 *
	 * @return string
	 */
	private static function verified_key() {
		return 'verified_' . self::visitor_key();
	}

	/**
	 * Current pending record, or null.
	 *
	 * @return array|null
	 */
	public static function get_pending() {
		$raw = MyOTP_PV_Store::instance()->get( self::pending_key() );
		if ( null === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return ( is_array( $data ) && ! empty( $data['phone'] ) ) ? $data : null;
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
	 * Give back one reserved attempt (provider never reached).
	 */
	public static function release_attempt() {
		myotp_pv_release_attempt( MyOTP_PV_Store::instance(), self::pending_key() );
	}

	/**
	 * Forget the pending record.
	 */
	public static function clear_pending() {
		MyOTP_PV_Store::instance()->delete( self::pending_key() );
	}

	/**
	 * Mark the phone verified now (state "verified").
	 *
	 * @param string $phone Digits.
	 */
	public static function set_verified( $phone ) {
		MyOTP_PV_Store::instance()->set(
			self::verified_key(),
			myotp_pv_json(
				array(
					'phone' => $phone,
					'at'    => time(),
					'state' => 'verified',
				)
			),
			self::VERIFIED_TTL
		);
	}

	/**
	 * The verified phone for this visitor, or empty when none, consumed or expired.
	 *
	 * @return string
	 */
	public static function verified_phone() {
		$raw = MyOTP_PV_Store::instance()->get( self::verified_key() );
		return myotp_pv_verified_phone_from( null === $raw ? null : json_decode( $raw, true ), time(), self::VERIFIED_TTL );
	}

	/**
	 * Atomically consume the verification for one order or account.
	 * Returns the phone when this caller won, empty otherwise.
	 *
	 * @param string $tag Consumer tag, e.g. order:123 or user:7.
	 * @return string
	 */
	public static function claim_verified( $tag ) {
		return myotp_pv_claim_verified( MyOTP_PV_Store::instance(), self::verified_key(), $tag, time(), self::VERIFIED_TTL );
	}

	/**
	 * Drop the verified record (a new send starts over).
	 */
	public static function clear_verified() {
		MyOTP_PV_Store::instance()->delete( self::verified_key() );
	}
}
