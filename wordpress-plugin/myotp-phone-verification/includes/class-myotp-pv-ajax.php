<?php
/**
 * AJAX endpoints. The API key stays on the server; the browser only ever
 * talks to admin-ajax.php.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for send, verify and the admin test send.
 */
class MyOTP_PV_Ajax {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_ajax_myotp_pv_send', array( __CLASS__, 'send' ) );
		add_action( 'wp_ajax_nopriv_myotp_pv_send', array( __CLASS__, 'send' ) );
		add_action( 'wp_ajax_myotp_pv_verify', array( __CLASS__, 'verify' ) );
		add_action( 'wp_ajax_nopriv_myotp_pv_verify', array( __CLASS__, 'verify' ) );
		add_action( 'wp_ajax_myotp_pv_test', array( __CLASS__, 'admin_test' ) );
	}

	/**
	 * Read a POST field as a string.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function field( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Refuse with the cooldown message.
	 *
	 * @param int $seconds Seconds left on the cooldown.
	 */
	private static function refuse_cooldown( $seconds ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %d: minutes to wait */
					__( 'Too many wrong codes for this number. Try again in %d minutes.', 'myotp-phone-verification' ),
					max( 1, (int) ceil( $seconds / 60 ) )
				),
			),
			429
		);
	}

	/**
	 * Public: send a code to the number in the request.
	 */
	public static function send() {
		check_ajax_referer( 'myotp_pv_public', 'nonce' );

		$phone = myotp_pv_normalize_phone( self::field( 'phone' ) );
		if ( ! myotp_pv_is_valid_phone( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the number with the country code, digits only, for example 14155551234.', 'myotp-phone-verification' ) ), 400 );
		}

		$cooldown = MyOTP_PV_Session::cooldown_remaining( $phone );
		if ( $cooldown > 0 ) {
			self::refuse_cooldown( $cooldown );
		}

		// Snapshot the rows this request may transition; every write below is guarded by these.
		$pending_raw  = MyOTP_PV_Session::pending_raw();
		$pending      = MyOTP_PV_Session::decode_pending( $pending_raw );
		$verified_raw = MyOTP_PV_Session::verified_raw();

		if ( MyOTP_PV_Session::verified_is_used( $verified_raw ) ) {
			// A checkout or registration holds a claim on this visitor's verification.
			wp_send_json_error( array( 'message' => __( 'A checkout is using this verification. Finish it first.', 'myotp-phone-verification' ) ), 409 );
		}

		$limit = MyOTP_PV_Session::take_send( $phone );
		if ( ! $limit['allowed'] ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: minutes to wait */
						__( 'Too many codes requested. Try again in %d minutes.', 'myotp-phone-verification' ),
						max( 1, (int) ceil( $limit['retry_after'] / 60 ) )
					),
				),
				429
			);
		}

		// First try without force_send so this visitor's own unexpired code is reused, not re-billed.
		$result = MyOTP_PV_Api::generate( $phone, false );

		if ( ! $result['ok'] && 409 === (int) $result['http'] ) {
			if ( null !== $pending && $pending['phone'] === $phone ) {
				// This visitor requested that challenge: keep using it, attempts intact. Nothing was billed.
				MyOTP_PV_Session::release_site_slot( $limit['taken'] );
				wp_send_json_success(
					array(
						'message'  => __( 'A code is already on its way to this number. Enter it below.', 'myotp-phone-verification' ),
						'phone'    => $phone,
						'existing' => true,
					)
				);
			}
			// Someone else's challenge is active for this number. Never attach to it:
			// send this visitor a challenge of their own (the send caps were already taken above).
			$result = MyOTP_PV_Api::generate( $phone, true );
		}

		if ( ! $result['ok'] ) {
			if ( ! empty( $result['transport'] ) ) {
				MyOTP_PV_Session::release_send( $limit['taken'] );
			} elseif ( (int) $result['http'] >= 500 || 409 === (int) $result['http'] ) {
				MyOTP_PV_Session::release_site_slot( $limit['taken'] );
			}
			wp_send_json_error( array( 'message' => $result['message'] ), 200 );
		}

		if ( ! MyOTP_PV_Session::install_pending( $phone, (string) $result['body']['message_id'], $pending_raw ) ) {
			// A parallel send for this visitor won; that challenge is the live one.
			wp_send_json_error( array( 'message' => __( 'Another code was just requested. Enter the code from the newest message.', 'myotp-phone-verification' ) ), 409 );
		}
		if ( null !== $verified_raw && ! MyOTP_PV_Session::clear_verified( $verified_raw ) ) {
			// The verified record changed while we were sending (a claim is in flight). Do not proceed.
			wp_send_json_error( array( 'message' => __( 'A checkout is using this verification. Finish it first.', 'myotp-phone-verification' ) ), 409 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Code sent. Enter it below.', 'myotp-phone-verification' ),
				'phone'   => $phone,
			)
		);
	}

	/**
	 * Public: verify the code for the pending challenge.
	 */
	public static function verify() {
		check_ajax_referer( 'myotp_pv_public', 'nonce' );

		$otp   = preg_replace( '/[^0-9]/', '', self::field( 'otp' ) );
		$phone = myotp_pv_normalize_phone( self::field( 'phone' ) );

		if ( ! myotp_pv_is_valid_otp( $otp ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the code you received.', 'myotp-phone-verification' ) ), 400 );
		}

		$pending = MyOTP_PV_Session::decode_pending( MyOTP_PV_Session::pending_raw() );
		if ( null === $pending ) {
			wp_send_json_error( array( 'message' => __( 'Request a code first.', 'myotp-phone-verification' ) ), 400 );
		}
		if ( '' !== $phone && $phone !== $pending['phone'] ) {
			wp_send_json_error( array( 'message' => __( 'The number changed after the code was sent. Send a new code.', 'myotp-phone-verification' ) ), 400 );
		}

		$cooldown = MyOTP_PV_Session::cooldown_remaining( $pending['phone'] );
		if ( $cooldown > 0 ) {
			self::refuse_cooldown( $cooldown );
		}

		$verified_raw = MyOTP_PV_Session::verified_raw();

		// Reserve the attempt atomically before the provider call so parallel
		// guesses cannot exceed the cap; it is given back unless the provider says "wrong code".
		$reserve = MyOTP_PV_Session::reserve_attempt();
		if ( $reserve['locked'] ) {
			self::exhaust( $reserve );
			self::refuse_cooldown( MyOTP_PV_Session::LOCK_TTL );
		}
		if ( ! $reserve['ok'] ) {
			wp_send_json_error( array( 'message' => __( 'Request a code first.', 'myotp-phone-verification' ) ), 400 );
		}
		$pending     = $reserve['pending'];
		$pending_raw = $reserve['raw'];

		$result = MyOTP_PV_Api::verify( $pending['phone'], $otp, (string) $pending['message_id'] );
		if ( ! $result['ok'] ) {
			// Transport failure or any non-2xx: not a wrong code, count nothing.
			MyOTP_PV_Session::release_attempt();
			wp_send_json_error( array( 'message' => $result['message'] ), 200 );
		}

		$status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '';
		if ( 'success' !== $status ) {
			$text = isset( $result['body']['message'] ) && is_string( $result['body']['message'] )
				? $result['body']['message']
				: __( 'That code is not correct.', 'myotp-phone-verification' );
			if ( 'expired' === $status ) {
				// Challenge is dead; drop it without counting.
				MyOTP_PV_Session::clear_pending( $pending_raw );
			} elseif ( 'failed' === $status ) {
				// The one answer that counts. At the fifth, retire this challenge and start the cooldown.
				if ( $reserve['attempts'] >= MyOTP_PV_Session::MAX_ATTEMPTS ) {
					self::exhaust( $reserve );
				}
			} else {
				MyOTP_PV_Session::release_attempt();
			}
			wp_send_json_error(
				array(
					'message'   => $text,
					'status'    => $status,
					'remaining' => 'failed' === $status ? max( 0, MyOTP_PV_Session::MAX_ATTEMPTS - $reserve['attempts'] ) : null,
				),
				200
			);
		}

		if ( ! MyOTP_PV_Session::set_verified( $pending['phone'], $verified_raw ) ) {
			// The verified record changed under us (a claim is in flight, or a parallel
			// verification won). The code was right, so the attempt is not charged.
			MyOTP_PV_Session::release_attempt();
			wp_send_json_error( array( 'message' => __( 'Verification state changed. Try again.', 'myotp-phone-verification' ) ), 409 );
		}
		MyOTP_PV_Session::clear_pending( $pending_raw );

		wp_send_json_success(
			array(
				'message' => __( 'Phone number verified.', 'myotp-phone-verification' ),
				'phone'   => $pending['phone'],
			)
		);
	}

	/**
	 * Retire this visitor's challenge (guarded delete) and start their
	 * cooldown on the number. Nothing is keyed on the phone alone.
	 *
	 * @param array $reserve Result of reserve_attempt().
	 */
	private static function exhaust( array $reserve ) {
		MyOTP_PV_Session::start_cooldown( $reserve['pending']['phone'] );
		MyOTP_PV_Session::clear_pending( $reserve['raw'] );
	}

	/**
	 * Admin: send a test code from the settings page.
	 */
	public static function admin_test() {
		check_ajax_referer( 'myotp_pv_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'myotp-phone-verification' ) ), 403 );
		}

		$phone = myotp_pv_normalize_phone( self::field( 'phone' ) );
		if ( ! myotp_pv_is_valid_phone( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the number with the country code, digits only, for example 14155551234.', 'myotp-phone-verification' ) ), 400 );
		}

		$result = MyOTP_PV_Api::generate( $phone, true );
		if ( ! $result['ok'] ) {
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'http'    => $result['http'],
				),
				200
			);
		}

		$body = $result['body'];
		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: 1: phone number, 2: message id */
					__( 'Sent to %1$s. Message ID %2$s.', 'myotp-phone-verification' ),
					$phone,
					(string) $body['message_id']
				),
				'status'     => isset( $body['status'] ) ? (string) $body['status'] : '',
				'cost'       => isset( $body['cost'] ) ? $body['cost'] : null,
				'expires_at' => isset( $body['expires_at'] ) ? (string) $body['expires_at'] : '',
			)
		);
	}
}
