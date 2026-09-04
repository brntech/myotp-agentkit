<?php
/**
 * Thin client for POST /generate_otp and POST /verify_otp.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for the MyOTP API.
 */
class MyOTP_PV_Api {

	/**
	 * POST JSON to the API. Returns array( ok, http, body, message, transport ).
	 * transport is true when the request never got an HTTP answer.
	 *
	 * @param string $path    Endpoint path, e.g. /generate_otp.
	 * @param array  $payload JSON body.
	 * @return array
	 */
	public static function post( $path, array $payload ) {
		$options = myotp_pv_get_options();
		$api_key = $options['api_key'];

		if ( '' === $api_key ) {
			return array(
				'ok'        => false,
				'http'      => 0,
				'body'      => null,
				'transport' => true,
				'message'   => __( 'Phone verification is not configured on this site yet.', 'myotp-phone-verification' ),
			);
		}

		$response = wp_remote_post(
			MYOTP_PV_API_BASE . $path,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'X-API-Key'    => $api_key,
					'User-Agent'   => 'myotp-phone-verification/' . MYOTP_PV_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'        => false,
				'http'      => 0,
				'body'      => null,
				'transport' => true,
				'message'   => __( 'Could not reach the verification service. Try again in a moment.', 'myotp-phone-verification' ),
			);
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$ok   = $http >= 200 && $http < 300 && is_array( $body );

		return array(
			'ok'        => $ok,
			'http'      => $http,
			'body'      => is_array( $body ) ? $body : null,
			'transport' => false,
			'message'   => $ok ? '' : myotp_pv_error_message( $body, $http, __( 'The verification service returned an error.', 'myotp-phone-verification' ) ),
		);
	}

	/**
	 * Send a code. With $force false an unexpired code for the same number
	 * is not re-billed and the API answers 409; the caller then decides
	 * whether to resend with $force true (a challenge of its own).
	 *
	 * @param string $phone Digits only.
	 * @param bool   $force Pass force_send true.
	 * @return array
	 */
	public static function generate( $phone, $force = false ) {
		$options = myotp_pv_get_options();
		$payload = array(
			'phone_number' => $phone,
			'otp_length'   => (int) $options['otp_length'],
			'otp_validity' => (int) $options['otp_validity'],
			'channel'      => $options['channel'],
			'force_send'   => (bool) $force,
		);
		if ( '' !== $options['brand'] ) {
			$payload['brand'] = $options['brand'];
		}
		$result = self::post( '/generate_otp', $payload );
		if ( $result['ok'] && ! myotp_pv_is_send_body( $result['body'] ) ) {
			$result['ok']      = false;
			$result['message'] = __( 'The verification service gave an unexpected answer. Try again.', 'myotp-phone-verification' );
		}
		return $result;
	}

	/**
	 * Verify a code.
	 *
	 * @param string $phone      Digits only.
	 * @param string $otp        Code typed by the visitor.
	 * @param string $message_id Message ID from generate, may be empty.
	 * @return array
	 */
	public static function verify( $phone, $otp, $message_id = '' ) {
		$payload = array(
			'phone_number' => $phone,
			'otp'          => $otp,
		);
		if ( '' !== $message_id ) {
			$payload['message_id'] = $message_id;
		}
		return self::post( '/verify_otp', $payload );
	}
}
