<?php
/**
 * WooCommerce classic checkout: verify the billing phone before the order
 * can be placed. Loaded only when WooCommerce is active.
 *
 * Proof lifecycle: validation claims the verified record for this request
 * (verified -> claiming:<phone>:<rid>), so a second checkout sharing the
 * same proof fails validation instead of creating an order. Once the order
 * exists, the claim is consumed (-> consumed:order:<id>) and the order is
 * stamped. If checkout fails after validation (payment declined, for
 * example) the record stays "claiming" until it expires and the shopper
 * must verify again.
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
	 * Phone claimed by this request's validation, empty when none.
	 *
	 * @var string
	 */
	private static $claimed = '';

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
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'consume' ) );
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
	 * Block the order until the billing phone matches the verified number,
	 * then claim the proof for this request so no other checkout can use it.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors collected so far.
	 */
	public static function validate( $data, $errors ) {
		self::$claimed = '';
		if ( ! self::required() ) {
			return;
		}
		$billing  = isset( $data['billing_phone'] ) ? myotp_pv_normalize_phone( $data['billing_phone'] ) : '';
		$verified = MyOTP_PV_Session::verified_phone();

		if ( '' === $verified ) {
			if ( MyOTP_PV_Session::verified_is_used() ) {
				$errors->add( 'myotp_pv_claimed', __( 'This phone verification was already used by another checkout. Verify your number again.', 'myotp-phone-verification' ) );
				return;
			}
			$errors->add( 'myotp_pv_unverified', __( 'Verify your billing phone number before placing the order. Use the Send code button under the billing details.', 'myotp-phone-verification' ) );
			return;
		}
		if ( $billing !== $verified ) {
			$errors->add( 'myotp_pv_mismatch', __( 'The billing phone number does not match the number you verified. Verify the new number or change it back.', 'myotp-phone-verification' ) );
			return;
		}
		$claimed = MyOTP_PV_Session::claim_verified( $billing );
		if ( $claimed !== $billing ) {
			$errors->add( 'myotp_pv_claimed', __( 'This phone verification was already used by another checkout. Verify your number again.', 'myotp-phone-verification' ) );
			return;
		}
		self::$claimed = $claimed;
	}

	/**
	 * Once the order exists: write the durable stamp first, then consume
	 * this request's claim. If the consume CAS loses (the record changed
	 * under us) the stamp is removed again and the order gets a note, so
	 * an order is never left stamped without a consumed proof.
	 *
	 * @param WC_Order $order The created order.
	 */
	public static function consume( $order ) {
		if ( ! self::required() || ! is_object( $order ) || '' === self::$claimed ) {
			return;
		}
		$phone         = self::$claimed;
		self::$claimed = '';

		$order->update_meta_data( self::ORDER_META, $phone );
		$order->save();

		$consumed = MyOTP_PV_Session::consume_claim( $phone, 'order:' . $order->get_id() );
		if ( $consumed === $phone ) {
			return;
		}
		$order->delete_meta_data( self::ORDER_META );
		$order->save();
		$order->add_order_note( __( 'MyOTP: the phone verification claimed at checkout could not be consumed for this order (it changed or expired in between). Billing phone is unverified.', 'myotp-phone-verification' ) );
	}
}
