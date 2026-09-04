<?php
/**
 * WooCommerce classic checkout: verify the billing phone before the order
 * can be placed. Loaded only when WooCommerce is active.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce classic checkout integration.
 */
class MyOTP_PV_WooCommerce {

	const ORDER_META = '_myotp_verified_phone';

	/**
	 * Hook registration.
	 */
	public static function init() {
		$o = myotp_pv_get_options();
		if ( empty( $o['wc_enabled'] ) ) {
			return;
		}
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'widget' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'claim' ) );
	}

	/**
	 * Whether the current visitor must verify.
	 *
	 * @return bool
	 */
	public static function required() {
		$o = myotp_pv_get_options();
		if ( ! empty( $o['wc_guests_only'] ) && is_user_logged_in() ) {
			return false;
		}
		return true;
	}

	/**
	 * Output the widget under the billing fields, bound to #billing_phone.
	 */
	public static function widget() {
		if ( ! self::required() ) {
			return;
		}
		$html = MyOTP_PV_Widget::render(
			array(
				'context'        => 'checkout',
				'phone_selector' => '#billing_phone',
				'verified'       => MyOTP_PV_Session::verified_phone(),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built.
	}

	/**
	 * Block the order until the billing phone matches the verified number.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors collected so far.
	 */
	public static function validate( $data, $errors ) {
		if ( ! self::required() ) {
			return;
		}
		$billing  = isset( $data['billing_phone'] ) ? myotp_pv_normalize_phone( $data['billing_phone'] ) : '';
		$verified = MyOTP_PV_Session::verified_phone();

		if ( '' === $verified ) {
			$errors->add( 'myotp_pv_unverified', __( 'Verify your billing phone number before placing the order. Use the Send code button under the billing details.', 'myotp-phone-verification' ) );
			return;
		}
		if ( $billing !== $verified ) {
			$errors->add( 'myotp_pv_mismatch', __( 'The billing phone number does not match the number you verified. Verify the new number or change it back.', 'myotp-phone-verification' ) );
		}
	}

	/**
	 * Atomically claim the verification for this order once it exists.
	 * The CAS moves the record from "verified" to "consumed:order:<id>";
	 * only the winner stamps the order. A loser (a parallel checkout that
	 * passed validation on the same proof) gets an order note instead.
	 *
	 * @param WC_Order $order The created order.
	 */
	public static function claim( $order ) {
		if ( ! self::required() || ! is_object( $order ) ) {
			return;
		}
		$phone = MyOTP_PV_Session::claim_verified( 'order:' . $order->get_id() );
		if ( '' !== $phone ) {
			$order->update_meta_data( self::ORDER_META, $phone );
			$order->save();
		} else {
			$order->add_order_note( __( 'MyOTP: phone verification could not be claimed for this order (already used by another order or expired). Billing phone is unverified.', 'myotp-phone-verification' ) );
		}
		MyOTP_PV_Session::clear_pending();
	}
}
