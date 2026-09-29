<?php
/**
 * "Ask for the code after payment" for express wallet orders (Apple Pay,
 * Google Pay and similar buttons that place the order without the checkout
 * form). The order is marked at creation; when the gateway reports the
 * payment, the status it would set (processing or completed) is recorded
 * and the order goes on-hold instead. The shopper verifies the order's own
 * billing phone from the order-received page or, logged in, from My
 * account > View order. On success the order is stamped like any verified
 * order and moved to the recorded status. Nothing runs on a schedule: an
 * order that is never verified stays on-hold for the shop.
 *
 * Every request field here is untrusted. The order is found by id and must
 * present its own order key (compared in constant time, as WooCommerce does
 * on the order-received page); an order that belongs to a customer account
 * also needs that customer logged in. A missing order, a wrong key and an
 * order that is not waiting all get the same answer. The phone is always
 * read from the order, never from the request.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-payment phone verification for held express wallet orders.
 */
class MyOTP_PV_Order_Verify {

	const AWAITING   = '_myotp_pv_awaiting';
	const RESTORE    = '_myotp_pv_restore_status';
	const MAX_ORDER  = 3;
	const PAID_STATE = array( 'processing', 'completed' );

	/**
	 * True while this class changes an order's status itself.
	 *
	 * @var bool
	 */
	private static $restoring = false;

	/**
	 * Hook registration. Runs whenever WooCommerce is active, so orders
	 * held under an earlier setting can still be verified.
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'hold_on_payment' ), 20, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'hold_on_status' ), 20, 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_box' ), 5 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'view_order_box' ), 5 );
		add_action( 'wp_ajax_myotp_pv_order_send', array( __CLASS__, 'send' ) );
		add_action( 'wp_ajax_nopriv_myotp_pv_order_send', array( __CLASS__, 'send' ) );
		add_action( 'wp_ajax_myotp_pv_order_verify', array( __CLASS__, 'verify' ) );
		add_action( 'wp_ajax_nopriv_myotp_pv_order_verify', array( __CLASS__, 'verify' ) );
	}

	/**
	 * Mark a new express wallet order as waiting for phone verification.
	 *
	 * @param WC_Order $order Order just created.
	 * @param string   $type  Wallet type.
	 */
	public static function mark( $order, $type ) {
		$order->update_meta_data( self::AWAITING, sanitize_key( $type ) );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: %s: wallet name, e.g. Apple Pay */
				__( 'MyOTP: express wallet payment (%s). Once paid, the order is held on-hold until the shopper verifies the billing phone on the order confirmation page.', 'myotp-phone-verification' ),
				myotp_pv_wallet_label( $type )
			)
		);
		if ( ! myotp_pv_is_valid_phone( myotp_pv_normalize_phone( (string) $order->get_billing_phone() ) ) ) {
			$order->add_order_note( __( 'MyOTP: the billing phone on this order cannot receive a code. Contact the customer before releasing it.', 'myotp-phone-verification' ) );
		}
	}

	/**
	 * True when the order is waiting for the shopper's phone verification.
	 *
	 * @param mixed $order Order or anything else.
	 * @return bool
	 */
	public static function is_awaiting( $order ) {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) && '' !== (string) $order->get_meta( self::AWAITING, true );
	}

	/**
	 * Payment reported for a waiting order: record the status the gateway
	 * asked for and hold the order instead.
	 *
	 * @param string $status   Status WooCommerce is about to set.
	 * @param int    $order_id Order id.
	 * @param mixed  $order    Order being paid, when WooCommerce passes it.
	 * @return string
	 */
	public static function hold_on_payment( $status, $order_id = 0, $order = null ) {
		$fetched = false;
		if ( ! is_object( $order ) ) {
			$order   = self::order( $order_id );
			$fetched = true;
		}
		if ( ! self::is_awaiting( $order ) ) {
			return $status;
		}
		$target = sanitize_key( (string) $status );
		if ( '' !== $target && 'on-hold' !== $target ) {
			$order->update_meta_data( self::RESTORE, $target );
			if ( $fetched ) {
				$order->save();
			}
		}
		$order->add_order_note( __( 'MyOTP: payment received. The order is on hold until the shopper verifies the billing phone.', 'myotp-phone-verification' ) );
		return 'on-hold';
	}

	/**
	 * Safety net for gateways that set processing or completed directly
	 * instead of through payment_complete(): a waiting order is put back
	 * on-hold. A shop manager (edit_shop_orders) may release it by hand.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order.
	 */
	public static function hold_on_status( $order_id, $from = '', $to = '', $order = null ) {
		if ( self::$restoring || ! in_array( $to, self::PAID_STATE, true ) ) {
			return;
		}
		if ( ! is_object( $order ) ) {
			$order = self::order( $order_id );
		}
		if ( ! self::is_awaiting( $order ) || current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order->update_meta_data( self::RESTORE, $to );
		self::$restoring = true;
		try {
			$order->update_status( 'on-hold', __( 'MyOTP: held until the shopper verifies the billing phone.', 'myotp-phone-verification' ) );
		} finally {
			self::$restoring = false;
		}
	}

	/**
	 * Order-received page: the verification box, only with the order's own
	 * key in the URL, and for an account order only to that customer.
	 *
	 * @param int $order_id Order id.
	 */
	public static function thankyou_box( $order_id ) {
		$order = self::order( $order_id );
		if ( null === $order || ! self::is_awaiting( $order ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the authorisation, as on the order-received page itself.
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! self::key_matches( $order, $key ) || ! self::owner_ok( $order ) ) {
			return;
		}
		self::render_box( $order );
	}

	/**
	 * My account > View order: the box for the logged-in owner.
	 *
	 * @param int $order_id Order id.
	 */
	public static function view_order_box( $order_id ) {
		$order = self::order( $order_id );
		if ( null === $order || ! self::is_awaiting( $order ) ) {
			return;
		}
		$customer = (int) $order->get_customer_id();
		if ( $customer <= 0 || ! is_user_logged_in() || get_current_user_id() !== $customer ) {
			return;
		}
		self::render_box( $order );
	}

	/**
	 * AJAX: send a code to the order's billing phone.
	 */
	public static function send() {
		$order = self::ajax_order();
		$id    = (int) $order->get_id();
		$phone = myotp_pv_normalize_phone( (string) $order->get_billing_phone() );
		if ( ! myotp_pv_is_valid_phone( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'The phone number on this order cannot receive a code. The shop will contact you.', 'myotp-phone-verification' ) ), 400 );
		}
		$store = MyOTP_PV_Store::instance();
		$cool  = myotp_pv_lock_remaining( $store, self::lock_key( $id ), time() );
		if ( $cool > 0 ) {
			self::refuse_cooldown( $cool );
		}

		$pending_raw = $store->get( self::pending_key( $id ) );
		$pending     = MyOTP_PV_Session::decode_pending( $pending_raw );

		$limit = MyOTP_PV_Session::take_send( $phone, array( array( 'send_o_' . $id, self::MAX_ORDER ) ) );
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

		$result = MyOTP_PV_Api::generate( $phone, false );
		if ( ! $result['ok'] && 409 === (int) $result['http'] ) {
			if ( null !== $pending && $pending['phone'] === $phone ) {
				MyOTP_PV_Session::release_site_slot( $limit['taken'] );
				wp_send_json_success( array( 'message' => __( 'A code is already on its way to this number. Enter it below.', 'myotp-phone-verification' ) ) );
			}
			$result = MyOTP_PV_Api::generate( $phone, true );
		}
		if ( ! $result['ok'] ) {
			if ( ! empty( $result['transport'] ) ) {
				MyOTP_PV_Session::release_send( $limit['taken'] );
			} elseif ( (int) $result['http'] >= 500 || 409 === (int) $result['http'] ) {
				MyOTP_PV_Session::release_site_slot( $limit['taken'] );
			}
			MyOTP_PV_Api::report( __( 'Sending a code failed', 'myotp-phone-verification' ), $result );
			wp_send_json_error( array( 'message' => MyOTP_PV_Api::shopper_send_error() ), 200 );
		}

		$ttl  = MyOTP_PV_Session::pending_ttl();
		$done = myotp_pv_install(
			$store,
			self::pending_key( $id ),
			$pending_raw,
			array(
				'phone'      => $phone,
				'message_id' => (string) $result['body']['message_id'],
				'reserved'   => 0,
				'failed'     => 0,
				'sent_at'    => time(),
				'exp'        => time() + $ttl,
			),
			$ttl
		);
		if ( null === $done ) {
			wp_send_json_error( array( 'message' => __( 'State changed. Request a new code.', 'myotp-phone-verification' ) ), 409 );
		}
		wp_send_json_success( array( 'message' => __( 'Code sent to the phone number on this order. Enter it below.', 'myotp-phone-verification' ) ) );
	}

	/**
	 * AJAX: check the code; on success stamp the order and release it.
	 */
	public static function verify() {
		$order = self::ajax_order();
		$id    = (int) $order->get_id();
		$store = MyOTP_PV_Store::instance();
		$pkey  = self::pending_key( $id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_order().
		$otp = isset( $_POST['otp'] ) && is_string( $_POST['otp'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['otp'] ) ) ) : '';
		if ( ! myotp_pv_is_valid_otp( $otp ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the code you received.', 'myotp-phone-verification' ) ), 400 );
		}
		$phone   = myotp_pv_normalize_phone( (string) $order->get_billing_phone() );
		$pending = MyOTP_PV_Session::decode_pending( $store->get( $pkey ) );
		if ( null === $pending ) {
			wp_send_json_error( array( 'message' => __( 'Request a code first.', 'myotp-phone-verification' ) ), 400 );
		}
		if ( (string) $pending['phone'] !== $phone ) {
			// The code went to another number than the one the order holds now.
			wp_send_json_error( array( 'message' => __( 'The phone number on this order changed. Send a new code.', 'myotp-phone-verification' ) ), 400 );
		}
		$cool = myotp_pv_lock_remaining( $store, self::lock_key( $id ), time() );
		if ( $cool > 0 ) {
			self::refuse_cooldown( $cool );
		}

		$reserve = myotp_pv_reserve_attempt( $store, $pkey, MyOTP_PV_Session::MAX_ATTEMPTS, time() );
		if ( $reserve['locked'] ) {
			self::exhaust( $id, $reserve['raw'] );
			self::refuse_cooldown( MyOTP_PV_Session::LOCK_TTL );
		}
		if ( ! $reserve['ok'] ) {
			if ( null !== $reserve['pending'] ) {
				wp_send_json_error( array( 'message' => __( 'Too many checks in progress for this code. Wait a moment and try again.', 'myotp-phone-verification' ) ), 429 );
			}
			wp_send_json_error( array( 'message' => __( 'Request a code first.', 'myotp-phone-verification' ) ), 400 );
		}
		$message_id = (string) $reserve['pending']['message_id'];

		$result = MyOTP_PV_Api::verify( $phone, $otp, $message_id );
		if ( ! $result['ok'] ) {
			myotp_pv_release_attempt( $store, $pkey, $message_id );
			MyOTP_PV_Api::report( __( 'Checking a code failed', 'myotp-phone-verification' ), $result );
			wp_send_json_error( array( 'message' => MyOTP_PV_Api::shopper_check_error() ), 200 );
		}
		$status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : '';
		if ( 'success' !== $status ) {
			$text = MyOTP_PV_Ajax::status_text( $status, $result );
			if ( 'expired' === $status ) {
				$store->delete( $pkey, $reserve['raw'] );
			} elseif ( 'failed' === $status ) {
				$settled = myotp_pv_record_failed( $store, $pkey, $message_id, MyOTP_PV_Session::MAX_ATTEMPTS, time() );
				if ( $settled['ok'] && $settled['exhausted'] ) {
					self::exhaust( $id, $settled['raw'] );
				}
			} else {
				myotp_pv_release_attempt( $store, $pkey, $message_id );
			}
			wp_send_json_error( array( 'message' => $text ), 200 );
		}

		// Exactly one success per order, even for two requests carrying the same right code.
		if ( ! $store->add( 'order_done_' . $id, '1', DAY_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'This order is already verified.', 'myotp-phone-verification' ) ), 409 );
		}
		self::complete( $order, $phone );
		$store->delete( $pkey, (string) $store->get( $pkey ) );
		wp_send_json_success( array( 'message' => __( 'Phone number verified. Thank you, the shop can now process your order.', 'myotp-phone-verification' ) ) );
	}

	/**
	 * Stamp the order as verified, clear the waiting marker, and move it to
	 * the status the gateway asked for when the payment came in.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $phone Verified digits (the order's billing phone).
	 */
	private static function complete( $order, $phone ) {
		$restore = sanitize_key( (string) $order->get_meta( self::RESTORE, true ) );
		$order->update_meta_data( MyOTP_PV_WooCommerce::ORDER_META, $phone );
		$order->delete_meta_data( self::AWAITING );
		$order->delete_meta_data( self::RESTORE );
		$order->save();
		$order->add_order_note( __( 'MyOTP: billing phone verified by the shopper after the express wallet payment.', 'myotp-phone-verification' ) );
		if ( '' === $restore || 'on-hold' === $restore || ! $order->has_status( 'on-hold' ) ) {
			return;
		}
		self::$restoring = true;
		try {
			$order->update_status( $restore, __( 'MyOTP: released after phone verification.', 'myotp-phone-verification' ) );
		} finally {
			self::$restoring = false;
		}
	}

	/**
	 * The order named by the AJAX request, after the nonce, the order key,
	 * the owner and the waiting marker all check out. Any failure gets one
	 * answer, so the endpoint never tells whether another order exists.
	 *
	 * @return WC_Order
	 */
	private static function ajax_order() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is bound to this id and checked right below.
		$raw_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['order_id'] ) ) : '';
		$id     = 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $raw_id ) ? (int) $raw_id : 0;
		check_ajax_referer( 'myotp_pv_order_' . $id, 'nonce' );
		$key   = isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$order = $id > 0 ? self::order( $id ) : null;
		if ( null === $order || ! self::key_matches( $order, $key ) || ! self::owner_ok( $order ) || ! self::is_awaiting( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This order cannot be verified here.', 'myotp-phone-verification' ) ), 403 );
		}
		return $order;
	}

	/**
	 * Load an order, or null.
	 *
	 * @param mixed $order_id Order id.
	 * @return WC_Order|null
	 */
	private static function order( $order_id ) {
		$id = is_numeric( $order_id ) ? (int) $order_id : 0;
		if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $id );
		return is_object( $order ) && method_exists( $order, 'get_order_key' ) ? $order : null;
	}

	/**
	 * Constant-time order key check; an empty key never matches.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Key from the request.
	 * @return bool
	 */
	private static function key_matches( $order, $key ) {
		$expected = (string) $order->get_order_key();
		return '' !== $expected && is_string( $key ) && '' !== $key && hash_equals( $expected, $key );
	}

	/**
	 * A guest order passes; an account order only for that customer.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function owner_ok( $order ) {
		$customer = (int) $order->get_customer_id();
		return $customer <= 0 || ( is_user_logged_in() && get_current_user_id() === $customer );
	}

	/**
	 * The verification box. Everything printed is escaped here.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_box( $order ) {
		$id    = (int) $order->get_id();
		$phone = myotp_pv_normalize_phone( (string) $order->get_billing_phone() );
		MyOTP_PV_Widget::enqueue();
		if ( ! myotp_pv_is_valid_phone( $phone ) ) {
			echo '<div class="myotp-pv myotp-pv-order"><p class="myotp-pv-status"><span class="myotp-pv-err">' . esc_html__( 'This order is on hold until the phone number on it is verified, but that number cannot receive a code. The shop will contact you.', 'myotp-phone-verification' ) . '</span></p></div>';
			return;
		}
		$box = 'myotp-pv-order-' . $id;
		?>
		<div class="myotp-pv myotp-pv-order" id="<?php echo esc_attr( $box ); ?>"
			data-context="order"
			data-phone-selector=""
			data-verified="0"
			data-order="<?php echo esc_attr( (string) $id ); ?>"
			data-order-key="<?php echo esc_attr( (string) $order->get_order_key() ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'myotp_pv_order_' . $id ) ); ?>">
			<h2><?php esc_html_e( 'Verify your phone number', 'myotp-phone-verification' ); ?></h2>
			<p><?php esc_html_e( 'Your payment went through. The shop will process your order once you verify the phone number on it.', 'myotp-phone-verification' ); ?></p>
			<p class="myotp-pv-row">
				<label for="<?php echo esc_attr( $box ); ?>-phone"><?php esc_html_e( 'Phone number', 'myotp-phone-verification' ); ?></label>
				<input type="tel" class="myotp-pv-phone" id="<?php echo esc_attr( $box ); ?>-phone" value="<?php echo esc_attr( $phone ); ?>" readonly="readonly" />
			</p>
			<p class="myotp-pv-row myotp-pv-actions">
				<button type="button" class="button myotp-pv-send"><?php esc_html_e( 'Send code', 'myotp-phone-verification' ); ?></button>
			</p>
			<p class="myotp-pv-row myotp-pv-code-row" hidden>
				<label for="<?php echo esc_attr( $box ); ?>-code"><?php esc_html_e( 'Verification code', 'myotp-phone-verification' ); ?></label>
				<input type="text" class="myotp-pv-code" id="<?php echo esc_attr( $box ); ?>-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="8" />
				<button type="button" class="button myotp-pv-verify"><?php esc_html_e( 'Verify', 'myotp-phone-verification' ); ?></button>
			</p>
			<p class="myotp-pv-status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Refuse with the cooldown message.
	 *
	 * @param int $seconds Seconds left.
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
	 * Five wrong codes: cool this order down, then retire the challenge
	 * with a delete guarded by the value that carried the count.
	 *
	 * @param int    $id  Order id.
	 * @param string $raw Raw pending value.
	 */
	private static function exhaust( $id, $raw ) {
		$store = MyOTP_PV_Store::instance();
		myotp_pv_lock_phone( $store, self::lock_key( $id ), time(), MyOTP_PV_Session::LOCK_TTL );
		$store->delete( self::pending_key( $id ), (string) $raw );
	}

	/**
	 * Store key of an order's pending challenge.
	 *
	 * @param int $id Order id.
	 * @return string
	 */
	private static function pending_key( $id ) {
		return 'order_pending_' . (int) $id;
	}

	/**
	 * Store key of an order's cooldown (per order, never per phone alone).
	 *
	 * @param int $id Order id.
	 * @return string
	 */
	private static function lock_key( $id ) {
		return 'cool:order:' . (int) $id;
	}
}
