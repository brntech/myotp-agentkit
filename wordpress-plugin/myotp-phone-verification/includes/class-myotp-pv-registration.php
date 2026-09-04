<?php
/**
 * Phone verification on wp-login.php?action=register.
 *
 * Proof lifecycle: registration_errors claims the verified record for this
 * request (verified -> claiming:<phone>:<rid>) once every other check has
 * passed; register_new_user consumes it (-> consumed:user:<id>) and stamps
 * user meta. If core aborts the registration after our claim, the record
 * stays "claiming" until it expires and the visitor must verify again.
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
		if ( empty( $o['register_enabled'] ) ) {
			return;
		}
		add_action( 'register_form', array( __CLASS__, 'field' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'register_new_user', array( __CLASS__, 'save' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
	}

	/**
	 * Submitted phone from the hidden field, digits only.
	 *
	 * @return string
	 */
	private static function posted_phone() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core registration form has no nonce; verification state is server-side.
		return isset( $_POST['myotp_pv_phone'] ) ? myotp_pv_normalize_phone( sanitize_text_field( wp_unslash( $_POST['myotp_pv_phone'] ) ) ) : '';
	}

	/**
	 * Output the widget inside the registration form.
	 */
	public static function field() {
		$html = MyOTP_PV_Widget::render(
			array(
				'context'     => 'register',
				'phone_value' => self::posted_phone(),
				'verified'    => MyOTP_PV_Session::verified_phone(),
				'label'       => __( 'Phone number (with country code)', 'myotp-phone-verification' ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built.
	}

	/**
	 * Block registration until the submitted number equals the verified
	 * one, then claim the proof for this request. The claim happens only
	 * when no other registration error is present, so core will go on to
	 * create the account.
	 *
	 * @param WP_Error $errors     Errors so far.
	 * @param string   $login      Username.
	 * @param string   $user_email Email.
	 * @return WP_Error
	 */
	public static function validate( $errors, $login, $user_email ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		self::$claimed = '';
		$phone         = self::posted_phone();
		$verified      = MyOTP_PV_Session::verified_phone();
		$clean         = ! ( $errors instanceof WP_Error ) || empty( $errors->get_error_codes() );

		if ( '' === $verified ) {
			if ( MyOTP_PV_Session::verified_is_used() ) {
				$errors->add( 'myotp_pv_claimed', __( 'This phone verification was already used. Verify your number again.', 'myotp-phone-verification' ) );
				return $errors;
			}
			$errors->add( 'myotp_pv_unverified', __( 'Verify your phone number before registering.', 'myotp-phone-verification' ) );
			return $errors;
		}
		if ( $phone !== $verified ) {
			$errors->add( 'myotp_pv_mismatch', __( 'The phone number changed after verification. Verify it again.', 'myotp-phone-verification' ) );
			return $errors;
		}
		if ( ! $clean ) {
			return $errors;
		}
		$claimed = MyOTP_PV_Session::claim_verified( $phone );
		if ( $claimed !== $phone ) {
			$errors->add( 'myotp_pv_claimed', __( 'This phone verification was already used. Verify your number again.', 'myotp-phone-verification' ) );
			return $errors;
		}
		self::$claimed = $claimed;
		return $errors;
	}

	/**
	 * Runs on register_new_user (successful public registration only).
	 * Writes the durable user meta first, then consumes this request's
	 * claim. If the meta write fails the claim is left as it is (it
	 * expires; the visitor verifies again) and the failure is logged. If
	 * the consume CAS loses, the meta is removed again so an account is
	 * never left stamped without a consumed proof.
	 *
	 * @param int $user_id New user id.
	 */
	public static function save( $user_id ) {
		if ( '' === self::$claimed || self::posted_phone() !== self::$claimed ) {
			return;
		}
		$phone         = self::$claimed;
		self::$claimed = '';

		if ( false === update_user_meta( $user_id, self::META, $phone ) ) {
			error_log( 'myotp-phone-verification: update_user_meta failed for user ' . (int) $user_id . '; verification left unconsumed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		$consumed = MyOTP_PV_Session::consume_claim( $phone, 'user:' . (int) $user_id );
		if ( $consumed === $phone ) {
			return;
		}
		delete_user_meta( $user_id, self::META );
		error_log( 'myotp-phone-verification: verification claim could not be consumed for user ' . (int) $user_id . '; stamp removed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
