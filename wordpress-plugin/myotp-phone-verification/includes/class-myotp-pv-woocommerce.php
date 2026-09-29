<?php
/**
 * WooCommerce checkout (classic shortcode and block/Store API): verify the
 * billing phone before the order can be placed. Loaded only when WooCommerce
 * is active.
 *
 * Proof lifecycle: validation claims the verified record for this request
 * (verified -> claiming:<phone>:<rid>), so a second checkout sharing the
 * same proof fails validation instead of creating an order. Once the order
 * exists, the claim is consumed (-> consumed:order:<id>) and the order is
 * stamped. If checkout fails after validation (payment declined, for
 * example) the record stays "claiming" until it expires and the shopper
 * must verify again.
 *
 * Accepted residual: consume() writes the durable stamp first and then
 * runs the consume CAS. A lost CAS at that point means the record changed
 * under us (expiry or the sweep), not that the order was unverified: the
 * proof was already claimed by this very request at validation, on the
 * same phone. So a stamp that survives a failed rollback is not a false
 * stamp; the rollback and its notes are best effort and stay as they are.
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
		// Block checkout (Store API). The update hook also fires on draft updates,
		// so validate_block() only acts on the POST that places the order.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_block' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'consume' ) );
		add_action( 'wp_footer', array( __CLASS__, 'block_widget' ) );
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
		$failure = self::check( isset( $data['billing_phone'] ) ? $data['billing_phone'] : '' );
		if ( null !== $failure ) {
			$errors->add( $failure[0], $failure[1] );
		}
	}

	/**
	 * Block checkout: the same gate, run when the order is placed (POST) and
	 * refused by throwing, which the Store API returns as a checkout notice.
	 * The billing phone comes from the order the request just populated.
	 *
	 * @param WC_Order        $order   The order being placed.
	 * @param WP_REST_Request $request The Store API request.
	 * @throws Exception Route exception carrying the message shown to the shopper.
	 */
	public static function validate_block( $order, $request = null ) {
		self::$claimed = '';
		if ( is_object( $request ) && method_exists( $request, 'get_method' ) && 'POST' !== $request->get_method() ) {
			return;
		}
		if ( ! self::required() ) {
			return;
		}
		$phone = is_object( $order ) && method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
		if ( '' === myotp_pv_normalize_phone( $phone ) ) {
			$failure = array( 'myotp_pv_no_phone', __( 'Enter your phone number and verify it before placing the order. Use the Send code button under the phone field.', 'myotp-phone-verification' ) );
		} else {
			$failure = self::check( $phone );
		}
		if ( null === $failure ) {
			return;
		}
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( $failure[0], $failure[1], 400 );
		}
		throw new \RuntimeException( $failure[1] );
	}

	/**
	 * Compare the billing phone with the verified number and claim the proof
	 * for this request. Sets $claimed on success.
	 *
	 * @param string $billing_phone Billing phone as entered.
	 * @return array|null Array( error code, message ) on failure, null on success.
	 */
	private static function check( $billing_phone ) {
		$billing  = myotp_pv_normalize_phone( $billing_phone );
		$verified = MyOTP_PV_Session::verified_phone();

		if ( '' === $verified ) {
			if ( MyOTP_PV_Session::verified_is_used() ) {
				return array( 'myotp_pv_claimed', __( 'This phone verification was already used by another checkout. Verify your number again.', 'myotp-phone-verification' ) );
			}
			return array( 'myotp_pv_unverified', __( 'Verify your billing phone number before placing the order. Use the Send code button under the billing details.', 'myotp-phone-verification' ) );
		}
		if ( $billing !== $verified ) {
			return array( 'myotp_pv_mismatch', __( 'The billing phone number does not match the number you verified. Verify the new number or change it back.', 'myotp-phone-verification' ) );
		}
		$claimed = MyOTP_PV_Session::claim_verified( $billing );
		if ( $claimed !== $billing ) {
			return array( 'myotp_pv_claimed', __( 'This phone verification was already used by another checkout. Verify your number again.', 'myotp-phone-verification' ) );
		}
		self::$claimed = $claimed;
		return null;
	}

	/**
	 * Block checkout has no server-side hook for the widget. Emit it once in a
	 * template; assets/js/myotp-block-checkout.js places it under the phone
	 * field and binds it.
	 */
	public static function block_widget() {
		if ( ! self::required() || ! function_exists( 'has_block' ) || ! has_block( 'woocommerce/checkout' ) ) {
			return;
		}
		wp_enqueue_script( 'myotp-pv-block', MYOTP_PV_URL . 'assets/js/myotp-block-checkout.js', array( 'myotp-pv' ), MYOTP_PV_VERSION, true );
		$html = MyOTP_PV_Widget::render(
			array(
				'context'        => 'checkout',
				'phone_selector' => '#billing-phone',
				'verified'       => MyOTP_PV_Session::verified_phone(),
			)
		);
		echo '<template id="myotp-pv-block-template">' . $html . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built.
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
		$order->add_order_note( __( 'MyOTP: the phone verification claimed at checkout could not be consumed for this order (it changed or expired in between). Billing phone is unverified.', 'myotp-phone-verification' ) );
		if ( self::rollback_stamp( $order ) ) {
			return;
		}
		// Second attempt: blank the value so nothing downstream reads it as a verified number.
		$blanked = false;
		try {
			$order->update_meta_data( self::ORDER_META, '' );
			$blanked = false !== $order->save();
		} catch ( \Throwable $e ) {
			$blanked = false;
		}
		error_log( 'myotp-phone-verification: verification stamp could not be rolled back after a lost claim on order ' . (int) $order->get_id() . ( $blanked ? ' (blanked instead)' : ' (blanking failed too)' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		$order->add_order_note( __( 'MyOTP: verification stamp could not be rolled back after a lost claim; treat as unverified.', 'myotp-phone-verification' ) );
	}

	/**
	 * Remove the stamp and persist; true only when both steps succeeded.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function rollback_stamp( $order ) {
		try {
			$order->delete_meta_data( self::ORDER_META );
			return false !== $order->save();
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
