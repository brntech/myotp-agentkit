<?php
/**
 * Renders the send/verify widget and registers its assets.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget renderer.
 */
class MyOTP_PV_Widget {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_assets' ) );
	}

	/**
	 * Register (not enqueue) the front-end assets.
	 */
	public static function register_assets() {
		if ( wp_script_is( 'myotp-pv', 'registered' ) ) {
			return;
		}
		wp_register_style( 'myotp-pv', MYOTP_PV_URL . 'assets/css/myotp-verify.css', array(), MYOTP_PV_VERSION );
		wp_register_script( 'myotp-pv', MYOTP_PV_URL . 'assets/js/myotp-verify.js', array(), MYOTP_PV_VERSION, true );
		wp_localize_script(
			'myotp-pv',
			'myotpPv',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'myotp_pv_public' ),
				'i18n'    => array(
					'sending'   => __( 'Sending...', 'myotp-phone-verification' ),
					'sent'      => __( 'Code sent. Enter it below.', 'myotp-phone-verification' ),
					'checking'  => __( 'Checking...', 'myotp-phone-verification' ),
					'verified'  => __( 'Phone number verified.', 'myotp-phone-verification' ),
					'network'   => __( 'Network error. Try again.', 'myotp-phone-verification' ),
					'needPhone' => __( 'Enter a phone number first.', 'myotp-phone-verification' ),
					'needCode'  => __( 'Enter the code you received.', 'myotp-phone-verification' ),
					'sendAgain' => __( 'Send again', 'myotp-phone-verification' ),
					'send'      => __( 'Send code', 'myotp-phone-verification' ),
					'verify'    => __( 'Verify', 'myotp-phone-verification' ),
				),
			)
		);
	}

	/**
	 * Front end: enqueue in the head on pages that will render the widget.
	 */
	public static function front_assets() {
		self::register_assets();
		$o    = myotp_pv_get_options();
		$need = false;
		if ( ! empty( $o['wc_enabled'] ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			$need = true;
		}
		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'myotp_verify' ) ) {
			$need = true;
		}
		if ( $need ) {
			self::enqueue();
		}
	}

	/**
	 * Login screen: enqueue when the registration form is shown.
	 */
	public static function login_assets() {
		self::register_assets();
		$o = myotp_pv_get_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing on the core login screen.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! empty( $o['register_enabled'] ) && 'register' === $action ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue the registered assets (fallback when render happens after head).
	 */
	public static function enqueue() {
		self::register_assets();
		wp_enqueue_style( 'myotp-pv' );
		wp_enqueue_script( 'myotp-pv' );
	}

	/**
	 * Build the widget markup.
	 *
	 * Keys: phone_selector (CSS selector of an external phone input; empty
	 * renders an inline input plus a hidden myotp_pv_phone field that always
	 * posts the number), phone_value (prefill for the inline input), verified
	 * (already-verified digits or empty), label (inline input label), context
	 * (free-form id: checkout, register, shortcode).
	 *
	 * @param array $args Arguments, see above.
	 * @return string HTML.
	 */
	public static function render( array $args = array() ) {
		$args = array_merge(
			array(
				'phone_selector' => '',
				'phone_value'    => '',
				'verified'       => '',
				'label'          => __( 'Phone number', 'myotp-phone-verification' ),
				'context'        => 'shortcode',
			),
			$args
		);
		self::enqueue();

		$id       = 'myotp-pv-' . wp_unique_id();
		$verified = '' !== $args['verified'];
		$value    = $verified ? $args['verified'] : $args['phone_value'];

		ob_start();
		?>
		<div class="myotp-pv" id="<?php echo esc_attr( $id ); ?>"
			data-context="<?php echo esc_attr( $args['context'] ); ?>"
			data-phone-selector="<?php echo esc_attr( $args['phone_selector'] ); ?>"
			data-verified="<?php echo $verified ? '1' : '0'; ?>">
			<?php if ( '' === $args['phone_selector'] ) : ?>
				<p class="myotp-pv-row">
					<label for="<?php echo esc_attr( $id ); ?>-phone"><?php echo esc_html( $args['label'] ); ?></label>
					<input type="tel" class="myotp-pv-phone" id="<?php echo esc_attr( $id ); ?>-phone"
						name="myotp_pv_phone_display" autocomplete="tel" inputmode="tel"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="14155551234" <?php disabled( $verified ); ?> />
					<input type="hidden" class="myotp-pv-phone-hidden" name="myotp_pv_phone" value="<?php echo esc_attr( $value ); ?>" />
				</p>
			<?php endif; ?>
			<p class="myotp-pv-row myotp-pv-actions">
				<button type="button" class="button myotp-pv-send" <?php disabled( $verified ); ?>>
					<?php esc_html_e( 'Send code', 'myotp-phone-verification' ); ?>
				</button>
			</p>
			<p class="myotp-pv-row myotp-pv-code-row" <?php echo $verified ? 'hidden' : ''; ?>>
				<label for="<?php echo esc_attr( $id ); ?>-code"><?php esc_html_e( 'Verification code', 'myotp-phone-verification' ); ?></label>
				<input type="text" class="myotp-pv-code" id="<?php echo esc_attr( $id ); ?>-code"
					inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="8" />
				<button type="button" class="button myotp-pv-verify">
					<?php esc_html_e( 'Verify', 'myotp-phone-verification' ); ?>
				</button>
			</p>
			<p class="myotp-pv-status" role="status" aria-live="polite">
				<?php if ( $verified ) : ?>
					<span class="myotp-pv-ok"><?php esc_html_e( 'Phone number verified.', 'myotp-phone-verification' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
