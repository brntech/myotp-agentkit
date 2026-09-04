<?php
/**
 * [myotp_verify] shortcode.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler.
 */
class MyOTP_PV_Shortcode {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_shortcode( 'myotp_verify', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the widget.
	 *
	 * Attributes:
	 *   label   Text above the phone input.
	 *   phone   Prefilled number.
	 *   context Free-form id passed back in the myotp:verified event.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'label'   => __( 'Phone number', 'myotp-phone-verification' ),
				'phone'   => '',
				'context' => 'shortcode',
			),
			$atts,
			'myotp_verify'
		);

		return MyOTP_PV_Widget::render(
			array(
				'label'       => sanitize_text_field( $atts['label'] ),
				'phone_value' => myotp_pv_normalize_phone( $atts['phone'] ),
				'context'     => sanitize_key( $atts['context'] ),
				'verified'    => MyOTP_PV_Session::verified_phone(),
			)
		);
	}
}
