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
 * Express wallet orders (Apple Pay, Google Pay and similar buttons that
 * place the order without the checkout form) are recognised by
 * myotp_pv_express_wallet_type(): the chosen gateway must be a wallet
 * gateway and the request must carry that gateway's own wallet marker.
 * The "wc_express" setting then decides: allow (no code, order note),
 * after (MyOTP_PV_Order_Verify holds the paid order until the shopper
 * verifies) or block (the gate below applies unchanged). A wallet order
 * never claims or stamps a checkout proof.
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
	 * True when this request pays an existing order (Store API
	 * /checkout/{id}); that order was not created at a checkout with the
	 * widget, so it is not gated, as on the classic order-pay page.
	 *
	 * @var bool
	 */
	private static $pay_existing = false;

	/**
	 * Wallet type when this request is an express wallet order that the
	 * setting lets through without a code (allow or after), empty otherwise.
	 *
	 * @var string
	 */
	private static $express = '';

	/**
	 * Setting in force when $express was set (allow or after).
	 *
	 * @var string
	 */
	private static $express_mode = '';

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
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'consume_block' ) );
		add_action( 'wp_footer', array( __CLASS__, 'block_widget' ) );
		// The phone field is required while the gate is on (WooCommerce's default is optional).
		add_filter( 'option_woocommerce_checkout_phone_field', array( __CLASS__, 'phone_field_visibility' ) );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'require_billing_phone' ), 20 );
	}

	/**
	 * WooCommerce's checkout phone setting (optional, required or hidden)
	 * as the checkout reads it: "required" while this visitor must verify,
	 * so both the block and the classic checkout show and require the
	 * field. Only the value read at checkout changes; the stored option is
	 * never rewritten, and admin screens see the merchant's own value.
	 *
	 * @param mixed $value Stored value.
	 * @return mixed
	 */
	public static function phone_field_visibility( $value ) {
		if ( 'required' === $value || ( is_admin() && ! wp_doing_ajax() ) || ! self::required() ) {
			return $value;
		}
		return 'required';
	}

	/**
	 * Classic checkout: the billing phone is required while the gate is on.
	 *
	 * @param mixed $fields Billing fields.
	 * @return mixed
	 */
	public static function require_billing_phone( $fields ) {
		if ( is_array( $fields ) && isset( $fields['billing_phone'] ) && is_array( $fields['billing_phone'] ) && self::required() ) {
			$fields['billing_phone']['required'] = true;
		}
		return $fields;
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
	 * The express wallet setting: allow, after or block. A stored value
	 * outside the three choices is treated as block, the strictest.
	 *
	 * @return string
	 */
	public static function express_mode() {
		$o    = myotp_pv_get_options();
		$mode = isset( $o['wc_express'] ) && is_string( $o['wc_express'] ) ? $o['wc_express'] : 'allow';
		return in_array( $mode, myotp_pv_express_modes(), true ) ? $mode : 'block';
	}

	/**
	 * When this request is an express wallet order and the setting does not
	 * block it, remember the wallet type for order creation and return true
	 * (the code gate is skipped). Otherwise return false and the gate applies.
	 *
	 * @param mixed $payment_method Gateway id from the request.
	 * @param array $fields         Wallet marker fields from the request.
	 * @return bool
	 */
	private static function take_express( $payment_method, array $fields ) {
		$mode = self::express_mode();
		if ( 'block' === $mode ) {
			return false;
		}
		$type = myotp_pv_express_wallet_type( $payment_method, $fields );
		if ( '' === $type ) {
			return false;
		}
		self::$express      = $type;
		self::$express_mode = $mode;
		return true;
	}

	/**
	 * Wallet marker fields posted with the classic checkout form. Only the
	 * marker keys are read, as plain strings.
	 *
	 * @return array<string, string>
	 */
	private static function classic_marker_fields() {
		$fields = array();
		foreach ( myotp_pv_express_marker_keys() as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this hook.
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$fields[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		return $fields;
	}

	/**
	 * A value read from the Store API request, or null.
	 *
	 * @param object $request Request.
	 * @param string $key     Parameter.
	 * @return mixed
	 */
	private static function request_param( $request, $key ) {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return $request->get_param( $key );
		}
		return null;
	}

	/**
	 * Handle an express wallet order once it exists: a note for "allow",
	 * the held-until-verified marker for "after". Never stamps the order as
	 * verified.
	 *
	 * @param WC_Order $order The created order.
	 */
	private static function express_order( $order ) {
		$type = self::$express;
		$mode = self::$express_mode;

		self::$express      = '';
		self::$express_mode = '';
		if ( ! is_object( $order ) || '' === $type ) {
			return;
		}
		if ( 'after' === $mode ) {
			MyOTP_PV_Order_Verify::mark( $order, $type );
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: wallet name, e.g. Apple Pay */
				__( 'MyOTP: express wallet payment (%s) placed without phone verification.', 'myotp-phone-verification' ),
				myotp_pv_wallet_label( $type )
			)
		);
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
		self::$claimed      = '';
		self::$express      = '';
		self::$express_mode = '';
		if ( ! self::required() ) {
			return;
		}
		$payment_method = is_array( $data ) && isset( $data['payment_method'] ) ? $data['payment_method'] : '';
		if ( self::take_express( $payment_method, self::classic_marker_fields() ) ) {
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
		self::$claimed                  = '';
		self::$express                  = '';
		self::$express_mode             = '';
		MyOTP_PV_Session::$key_override = '';
		self::$pay_existing             = is_object( $request ) && method_exists( $request, 'get_route' ) && 1 === preg_match( '#/checkout/\d+/?$#', (string) $request->get_route() );
		if ( self::$pay_existing ) {
			return;
		}
		if ( is_object( $request ) && method_exists( $request, 'get_method' ) && 'POST' !== $request->get_method() ) {
			return;
		}
		if ( ! self::required() ) {
			return;
		}
		$fields = myotp_pv_payment_data_fields( self::request_param( $request, 'payment_data' ) );
		if ( self::take_express( self::request_param( $request, 'payment_method' ), $fields ) ) {
			return;
		}
		// Pin the visitor key so order processing finds the same proof even if the account is created in between.
		MyOTP_PV_Session::$key_override = MyOTP_PV_Session::visitor_key();
		$phone                          = is_object( $order ) && method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
		if ( '' === myotp_pv_normalize_phone( $phone ) ) {
			$failure = array( 'myotp_pv_no_phone', __( 'Add a phone number to your address, then verify it with the Send code button before placing the order.', 'myotp-phone-verification' ) );
		} else {
			$failure = self::check( $phone, false );
		}
		if ( null === $failure ) {
			return;
		}
		self::refuse( $failure );
	}

	/**
	 * Block checkout: claim the proof only now, after every other validation
	 * (fields, stock, coupons) has passed, so an unrelated failure earlier in
	 * the request does not burn the shopper's verification. Then stamp and
	 * consume as the classic path does.
	 *
	 * @param WC_Order $order The order about to be paid.
	 * @throws Exception Route exception when the proof cannot be claimed.
	 */
	public static function consume_block( $order ) {
		self::$claimed = '';
		if ( '' !== self::$express && ! self::$pay_existing ) {
			self::express_order( $order );
			return;
		}
		self::$express = '';
		// Only an order that was gated at validation (which pinned the key) is consumed here.
		if ( self::$pay_existing || '' === MyOTP_PV_Session::$key_override ) {
			return;
		}
		try {
			$phone   = is_object( $order ) && method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
			$failure = '' === myotp_pv_normalize_phone( $phone ) ? array( 'myotp_pv_no_phone', __( 'Add a phone number to your address, then verify it with the Send code button before placing the order.', 'myotp-phone-verification' ) ) : self::check( $phone );
			if ( null !== $failure ) {
				self::refuse( $failure );
			}
			self::consume( $order );
		} finally {
			MyOTP_PV_Session::$key_override = '';
		}
	}

	/**
	 * Refuse the order the way the Store API expects.
	 *
	 * @param array $failure Array( error code, message ).
	 * @throws Exception Always.
	 */
	private static function refuse( $failure ) {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $failure[0] ), esc_html( $failure[1] ), 400 );
		}
		throw new \RuntimeException( esc_html( $failure[1] ) );
	}

	/**
	 * Compare the billing phone with the verified number and claim the proof
	 * for this request. Sets $claimed on success.
	 *
	 * @param string $billing_phone Billing phone as entered.
	 * @param bool   $claim         False to compare only, without claiming.
	 * @return array|null Array( error code, message ) on failure, null on success.
	 */
	private static function check( $billing_phone, $claim = true ) {
		$billing  = myotp_pv_normalize_phone( $billing_phone );
		$verified = MyOTP_PV_Session::verified_phone();

		if ( '' === $verified ) {
			if ( MyOTP_PV_Session::verified_is_used() ) {
				return array( 'myotp_pv_claimed', __( 'Please verify your phone number again for this order.', 'myotp-phone-verification' ) );
			}
			return array( 'myotp_pv_unverified', __( 'Verify your phone number before placing the order. Use the Send code button under your address.', 'myotp-phone-verification' ) );
		}
		if ( $billing !== $verified ) {
			return array( 'myotp_pv_mismatch', __( 'The billing phone number does not match the number you verified. Verify the new number or change it back.', 'myotp-phone-verification' ) );
		}
		if ( ! $claim ) {
			return null;
		}
		$claimed = MyOTP_PV_Session::claim_verified( $billing );
		if ( $claimed !== $billing ) {
			return array( 'myotp_pv_claimed', __( 'Please verify your phone number again for this order.', 'myotp-phone-verification' ) );
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
		if ( ! self::required() ) {
			return;
		}
		// is_checkout() also covers a checkout block that lives in a block template. The
		// script does nothing when the page has no block-checkout phone field.
		$is_block_page = function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' );
		if ( ! $is_block_page && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
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
		if ( '' !== self::$express ) {
			self::express_order( $order );
			return;
		}
		if ( ( ! self::required() && '' === MyOTP_PV_Session::$key_override ) || ! is_object( $order ) || '' === self::$claimed ) {
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
