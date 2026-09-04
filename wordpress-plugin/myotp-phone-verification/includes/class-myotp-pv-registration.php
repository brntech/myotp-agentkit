<?php
/**
 * Phone verification on wp-login.php?action=register.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration form integration.
 */
class MyOTP_PV_Registration {

	const META = 'myotp_verified_phone';

	/**
	 * Hook registration.
	 */
	public static function init() {
		$o = myotp_pv_get_options();
		if ( empty( $o['register_enabled'] ) ) {
			return;
		}
		add_action( 'register_form', array( __CLASS__, 'field' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'save' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
	}

	/**
	 * Output the widget inside the registration form.
	 */
	public static function field() {
		$prefill = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- prefill only, validated in validate().
		if ( isset( $_POST['myotp_pv_phone'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$prefill = myotp_pv_normalize_phone( sanitize_text_field( wp_unslash( $_POST['myotp_pv_phone'] ) ) );
		}
		$html = MyOTP_PV_Widget::render(
			array(
				'context'     => 'register',
				'phone_value' => $prefill,
				'verified'    => MyOTP_PV_Session::verified_phone(),
				'label'       => __( 'Phone number (with country code)', 'myotp-phone-verification' ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built.
	}

	/**
	 * Block registration until the submitted number matches the verified one.
	 *
	 * @param WP_Error $errors     Errors so far.
	 * @param string   $login      Username.
	 * @param string   $user_email Email.
	 * @return WP_Error
	 */
	public static function validate( $errors, $login, $user_email ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core registration form has no nonce; verification state is server-side.
		$phone    = isset( $_POST['myotp_pv_phone'] ) ? myotp_pv_normalize_phone( sanitize_text_field( wp_unslash( $_POST['myotp_pv_phone'] ) ) ) : '';
		$verified = MyOTP_PV_Session::verified_phone();

		if ( '' === $verified ) {
			$errors->add( 'myotp_pv_unverified', __( 'Verify your phone number before registering.', 'myotp-phone-verification' ) );
		} elseif ( '' !== $phone && $phone !== $verified ) {
			$errors->add( 'myotp_pv_mismatch', __( 'The phone number changed after verification. Verify it again.', 'myotp-phone-verification' ) );
		}
		return $errors;
	}

	/**
	 * Store the verified number as user meta.
	 *
	 * @param int $user_id New user id.
	 */
	public static function save( $user_id ) {
		$verified = MyOTP_PV_Session::verified_phone();
		if ( '' !== $verified ) {
			update_user_meta( $user_id, self::META, $verified );
			MyOTP_PV_Session::delete( 'verified' );
		}
	}

	/**
	 * Read-only display on the profile screen.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function profile( $user ) {
		$phone = get_user_meta( $user->ID, self::META, true );
		if ( '' === $phone ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Verified phone', 'myotp-phone-verification' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Phone number', 'myotp-phone-verification' ); ?></th>
				<td><code><?php echo esc_html( $phone ); ?></code></td>
			</tr>
		</table>
		<?php
	}
}
