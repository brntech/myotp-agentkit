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
	 * Refuse with the lock message.
	 *
	 * @param int $seconds Seconds left on the lock.
	 */
	private static function refuse_locked( $seconds ) {
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

		$lock = MyOTP_PV_Session::lock_remaining( $phone );
		if ( $lock > 0 ) {
			self::refuse_locked( $lock );
		}

		// Snapshot the rows this request may transition; every write below is guarded by these.
		$pending_raw  = MyOTP_PV_Session::pending_raw();
		$pending      = MyOTP_PV_Session::decode_pending( $pending_raw );
		$verified_raw = MyOTP_PV_Session::verified_raw();

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

		$result = MyOTP_PV_Api::generate( $phone );

		if ( ! $result['ok'] && 409 === (int) $result['http'] ) {
			// The provider still has an active code for this number; nothing was billed.
			MyOTP_PV_Session::release_site_slot( $limit['taken'] );
			if ( null !== $pending && $pending['phone'] === $phone && '' !== (string) $pending['message_id'] ) {
				// This visitor requested that challenge: let them keep using it, attempts intact.
				wp_send_json_success(
					array(
						'message'  => __( 'A code is already on its way to this number. Enter it below.', 'myotp-phone-verification' ),
						'phone'    => $phone,
						'existing' => true,
					)
				);
			}
			// Someone else's challenge, or none we know: never attach to it.
			wp_send_json_error( array( 'message' => __( 'A code for this number is still active. Wait for it to expire, then request a new one.', 'myotp-phone-verification' ) ), 409 );
		}

		if ( ! $result['ok'] ) {
			if ( ! empty( $result['transport'] ) ) {
				MyOTP_PV_Session::release_send( $limit['taken'] );
			} elseif ( (int) $result['http'] >= 500 ) {
				MyOTP_PV_Session::release_site_slot( $limit['taken'] );
			}
			wp_send_json_error( array( 'message' => $result['message'] ), 200 );
		}

		if ( ! MyOTP_PV_Session::install_pending( $phone, (string) $result['body']['message_id'], $pending_raw ) ) {
			// A parallel send for this visitor won; that challenge is the live one.
			wp_send_json_error( array( 'message' => __( 'Another code was just requested. Enter the code from the newest message.', 'myotp-phone-verification' ) ), 409 );
		}
		if ( null !== $verified_raw ) {
			MyOTP_PV_Session::clear_verified( $verified_raw );
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

		$lock = MyOTP_PV_Session::lock_remaining( $pending['phone'] );
		if ( $lock > 0 ) {
			self::refuse_locked( $lock );
		}

		$verified_raw = MyOTP_PV_Session::verified_raw();

		// Reserve the attempt atomically before the provider call so parallel
		// guesses cannot exceed the cap; give it back if the provider was never reached.
		$reserve = MyOTP_PV_Session::reserve_attempt();
		if ( $reserve['locked'] ) {
			MyOTP_PV_Session::lock_phone( $reserve['pending']['phone'] );
			MyOTP_PV_Session::clear_pending( $reserve['raw'] );
			self::refuse_locked( MyOTP_PV_Session::LOCK_TTL );
		}
		if ( ! $reserve['ok'] ) {
			wp_send_json_error( array( 'message' => __( 'Request a code first.', 'myotp-phone-verification' ) ), 400 );
		}
		$pending     = $reserve['pending'];
		$pending_raw = $reserve['raw'];

		$result = MyOTP_PV_Api::verify( $pending['phone'], $otp, (string) $pending['message_id'] );
		if ( ! $result['ok'] ) {
			if ( ! empty( $result['transport'] ) ) {
				MyOTP_PV_Session::release_attempt();
				wp_send_json_error( array( 'message' => $result['message'] ), 200 );
			}
			self::after_failed_answer( $reserve );
			wp_send_json_error( array( 'message' => $result['message'] ), 200 );
		}

		$status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '';
		if ( 'success' !== $status ) {
			$text = isset( $result['body']['message'] ) && is_string( $result['body']['message'] )
				? $result['body']['message']
				: __( 'That code is not correct.', 'myotp-phone-verification' );
			if ( 'expired' === $status ) {
				MyOTP_PV_Session::clear_pending( $pending_raw );
			} else {
				self::after_failed_answer( $reserve );
			}
			wp_send_json_error(
				array(
					'message'   => $text,
					'status'    => $status,
					'remaining' => max( 0, MyOTP_PV_Session::MAX_ATTEMPTS - $reserve['attempts'] ),
				),
				200
			);
		}

		MyOTP_PV_Session::set_verified( $pending['phone'], $verified_raw );
		MyOTP_PV_Session::clear_pending( $pending_raw );

		wp_send_json_success(
			array(
				'message' => __( 'Phone number verified.', 'myotp-phone-verification' ),
				'phone'   => $pending['phone'],
			)
		);
	}

	/**
	 * A provider answer that was not success: when this was the last
	 * allowed attempt, lock the phone (add only) and drop the challenge.
	 *
	 * @param array $reserve Result of reserve_attempt().
	 */
	private static function after_failed_answer( array $reserve ) {
		if ( $reserve['attempts'] >= MyOTP_PV_Session::MAX_ATTEMPTS ) {
			MyOTP_PV_Session::lock_phone( $reserve['pending']['phone'] );
			MyOTP_PV_Session::clear_pending( $reserve['raw'] );
		}
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

		$result = MyOTP_PV_Api::generate( $phone );
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
