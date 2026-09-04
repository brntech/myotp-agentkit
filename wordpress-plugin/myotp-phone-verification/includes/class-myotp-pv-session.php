<?php
/**
 * Per-visitor state. Counters, the pending challenge, the verified record
 * and phone locks all live in the atomic store (options table). Every
 * transition on a pending or verified row is guarded by the raw value the
 * caller read, so a stale request cannot overwrite or delete what another
 * request just wrote. The WooCommerce customer id or the user id names the
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
	const VERIFIED_TTL = 1800;
	const LOCK_TTL     = 900;
	const MAX_ATTEMPTS = 5;
	const MAX_VISITOR  = 5;
	const MAX_IP       = 10;
	const MAX_PHONE    = 3;
	const SITE_WINDOW  = 3600;
	const SITE_KEY     = 'send_site';

	/**
	 * Per-request random id used in claim states. Tests may overwrite it.
	 *
	 * @var string
	 */
	public static $request_id = '';

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
	 * Random id for this request, used to tie a claim to its consumer.
	 *
	 * @return string
	 */
	public static function request_id() {
		if ( '' === self::$request_id ) {
			self::$request_id = substr( md5( wp_generate_password( 32, false ) . microtime( true ) ), 0, 16 );
		}
		return self::$request_id;
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
	 * Site-wide sends per fixed one-hour window. Setting, then the
	 * myotp_pv_site_hourly_cap filter.
	 *
	 * @return int
	 */
	public static function site_hourly_cap() {
		$o   = myotp_pv_get_options();
		$cap = isset( $o['site_hourly_cap'] ) ? (int) $o['site_hourly_cap'] : 100;
		return max( 1, (int) apply_filters( 'myotp_pv_site_hourly_cap', $cap ) );
	}

	/**
	 * Pending record lifetime: the configured code validity, capped at a day.
	 *
	 * @return int
	 */
	public static function pending_ttl() {
		$o = myotp_pv_get_options();
		return min( 86400, max( 60, (int) $o['otp_validity'] ) );
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
				array( self::SITE_KEY, self::site_hourly_cap(), self::SITE_WINDOW ),
			),
			time(),
			MYOTP_PV_RATE_WINDOW
		);
	}

	/**
	 * Give every slot back (provider never reached).
	 *
	 * @param array $taken Pairs of key and window returned by take_send().
	 */
	public static function release_send( array $taken ) {
		foreach ( $taken as $pair ) {
			myotp_pv_release_slot( MyOTP_PV_Store::instance(), $pair[0], time(), (int) $pair[1] );
		}
	}

	/**
	 * Give only the site-wide slot back (provider answered 409 or 5xx: no code was billed).
	 *
	 * @param array $taken Pairs of key and window returned by take_send().
	 */
	public static function release_site_slot( array $taken ) {
		foreach ( $taken as $pair ) {
			if ( self::SITE_KEY === $pair[0] ) {
				myotp_pv_release_slot( MyOTP_PV_Store::instance(), $pair[0], time(), (int) $pair[1] );
			}
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
	 * Key for this visitor's cooldown on a phone (never keyed on the phone
	 * alone, so nobody can lock a number's owner out).
	 *
	 * @param string $phone Digits.
	 * @return string
	 */
	private static function cooldown_key( $phone ) {
		return 'cool:' . self::visitor_key() . ':' . $phone;
	}

	/**
	 * Raw pending value for this visitor, or null.
	 *
	 * @return string|null
	 */
	public static function pending_raw() {
		return MyOTP_PV_Store::instance()->get( self::pending_key() );
	}

	/**
	 * Decode a raw pending value.
	 *
	 * @param string|null $raw Raw value.
	 * @return array|null
	 */
	public static function decode_pending( $raw ) {
		if ( null === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return ( is_array( $data ) && ! empty( $data['phone'] ) && ! empty( $data['message_id'] ) ) ? $data : null;
	}

	/**
	 * Install a fresh pending challenge, guarded by the raw value read earlier.
	 *
	 * @param string      $phone      Digits.
	 * @param string      $message_id Message id from /generate_otp.
	 * @param string|null $expected   Raw pending value read before the provider call.
	 * @return bool True when written.
	 */
	public static function install_pending( $phone, $message_id, $expected ) {
		$ttl = self::pending_ttl();
		return null !== myotp_pv_install(
			MyOTP_PV_Store::instance(),
			self::pending_key(),
			$expected,
			array(
				'phone'      => $phone,
				'message_id' => (string) $message_id,
				'attempts'   => 0,
				'sent_at'    => time(),
				'exp'        => time() + $ttl,
			),
			$ttl
		);
	}

	/**
	 * Reserve one verify attempt.
	 *
	 * @return array{ok: bool, locked: bool, pending: array|null, attempts: int, raw: string|null}
	 */
	public static function reserve_attempt() {
		return myotp_pv_reserve_attempt( MyOTP_PV_Store::instance(), self::pending_key(), self::MAX_ATTEMPTS, time() );
	}

	/**
	 * Give back one reserved attempt on a specific challenge.
	 *
	 * @param string $message_id Challenge the attempt was reserved on.
	 * @return bool
	 */
	public static function release_attempt( $message_id ) {
		return myotp_pv_release_attempt( MyOTP_PV_Store::instance(), self::pending_key(), (string) $message_id );
	}

	/**
	 * Retire an exhausted challenge by CAS on the raw value last read.
	 *
	 * @param string $raw        Raw pending value last read.
	 * @param string $message_id Challenge id.
	 * @return bool
	 */
	public static function exhaust_challenge( $raw, $message_id ) {
		return myotp_pv_exhaust_challenge( MyOTP_PV_Store::instance(), self::pending_key(), (string) $raw, (string) $message_id, self::MAX_ATTEMPTS );
	}

	/**
	 * Delete the pending record only while it still holds $raw.
	 *
	 * @param string $raw Raw value that authorised the delete.
	 * @return bool
	 */
	public static function clear_pending( $raw ) {
		return MyOTP_PV_Store::instance()->delete( self::pending_key(), (string) $raw );
	}

	/**
	 * Start this visitor's cooldown on a phone after five wrong codes. add() only.
	 *
	 * @param string $phone Digits.
	 * @return bool
	 */
	public static function start_cooldown( $phone ) {
		return myotp_pv_lock_phone( MyOTP_PV_Store::instance(), self::cooldown_key( $phone ), time(), self::LOCK_TTL );
	}

	/**
	 * Seconds left on this visitor's cooldown for a phone, 0 when none.
	 *
	 * @param string $phone Digits.
	 * @return int
	 */
	public static function cooldown_remaining( $phone ) {
		return myotp_pv_lock_remaining( MyOTP_PV_Store::instance(), self::cooldown_key( $phone ), time() );
	}

	/**
	 * Raw verified value for this visitor, or null.
	 *
	 * @return string|null
	 */
	public static function verified_raw() {
		return MyOTP_PV_Store::instance()->get( self::verified_key() );
	}

	/**
	 * Mark the phone verified now (state "verified"), guarded by the raw
	 * verified value read earlier (null when there was none). Refuses to
	 * replace a record a checkout or registration has claimed or consumed.
	 *
	 * @param string      $phone    Digits.
	 * @param string|null $expected Raw verified value read earlier.
	 * @return bool
	 */
	public static function set_verified( $phone, $expected ) {
		if ( ! myotp_pv_verified_replaceable( $expected ) ) {
			return false;
		}
		return null !== myotp_pv_install(
			MyOTP_PV_Store::instance(),
			self::verified_key(),
			$expected,
			array(
				'phone' => $phone,
				'at'    => time(),
				'state' => 'verified',
			),
			self::VERIFIED_TTL
		);
	}

	/**
	 * The verified phone for this visitor, or empty when none, claimed, consumed or expired.
	 *
	 * @return string
	 */
	public static function verified_phone() {
		$raw = self::verified_raw();
		return myotp_pv_verified_phone_from( null === $raw ? null : json_decode( $raw, true ), time(), self::VERIFIED_TTL );
	}

	/**
	 * True when a checkout or registration currently holds a claim on this
	 * visitor's verification (state claiming:*). A consumed record is
	 * history, not in flight.
	 *
	 * @param string|null $raw Raw value to inspect, or null to read it.
	 * @return bool
	 */
	public static function verified_is_claiming( $raw = null ) {
		if ( null === $raw ) {
			$raw = self::verified_raw();
		}
		if ( null === $raw ) {
			return false;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['state'], $data['at'] ) ) {
			return false;
		}
		if ( (int) $data['at'] + self::VERIFIED_TTL <= time() ) {
			return false;
		}
		return 0 === strpos( (string) $data['state'], 'claiming:' );
	}

	/**
	 * True when this visitor's verification exists, is unexpired, and has
	 * already been claimed or consumed by a checkout or registration.
	 *
	 * @param string|null $raw Raw value to inspect, or null to read it.
	 * @return bool
	 */
	public static function verified_is_used( $raw = null ) {
		if ( null === $raw ) {
			$raw = self::verified_raw();
		}
		if ( null === $raw ) {
			return false;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['state'], $data['at'] ) ) {
			return false;
		}
		if ( (int) $data['at'] + self::VERIFIED_TTL <= time() ) {
			return false;
		}
		return 0 === strpos( (string) $data['state'], 'claiming:' ) || 0 === strpos( (string) $data['state'], 'consumed:' );
	}

	/**
	 * Claim the verification for this request: verified -> claiming:<phone>:<rid>.
	 *
	 * @param string $phone Phone that passed validation.
	 * @return string Phone when won, empty otherwise.
	 */
	public static function claim_verified( $phone ) {
		return myotp_pv_claim_verified( MyOTP_PV_Store::instance(), self::verified_key(), $phone, self::request_id(), time(), self::VERIFIED_TTL );
	}

	/**
	 * Consume this request's claim: claiming:<phone>:<rid> -> consumed:<tag>.
	 *
	 * @param string $phone Phone in the claim.
	 * @param string $tag   Consumer, e.g. order:123 or user:7.
	 * @return string Phone when won, empty otherwise.
	 */
	public static function consume_claim( $phone, $tag ) {
		return myotp_pv_consume_claim( MyOTP_PV_Store::instance(), self::verified_key(), $phone, self::request_id(), $tag, time(), self::VERIFIED_TTL );
	}

	/**
	 * Delete the verified record only while it still holds $raw.
	 *
	 * @param string $raw Raw value that authorised the delete.
	 * @return bool
	 */
	public static function clear_verified( $raw ) {
		return MyOTP_PV_Store::instance()->delete( self::verified_key(), (string) $raw );
	}
}
