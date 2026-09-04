<?php
/**
 * Settings > MyOTP.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page.
 */
class MyOTP_PV_Settings {

	const PAGE = 'myotp-phone-verification';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MYOTP_PV_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the Settings submenu.
	 */
	public static function menu() {
		add_options_page(
			__( 'MyOTP Phone Verification', 'myotp-phone-verification' ),
			__( 'MyOTP', 'myotp-phone-verification' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'myotp-phone-verification' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option with its sanitiser.
	 */
	public static function register() {
		register_setting(
			'myotp_pv',
			MYOTP_PV_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => myotp_pv_default_options(),
			)
		);
	}

	/**
	 * Sanitise, keeping the stored key when the mask comes back.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = get_option( MYOTP_PV_OPTION, array() );
		$clean   = myotp_pv_sanitize_options( $input, is_array( $current ) ? $current : array() );

		if ( is_array( $input ) && isset( $input['api_key'] ) ) {
			$submitted = trim( (string) $input['api_key'] );
			if ( '' !== $submitted && MYOTP_PV_KEY_MASK !== $submitted && $submitted !== $clean['api_key'] ) {
				add_settings_error( 'myotp_pv', 'api_key', __( 'The API key was not saved. Keys are 32 characters: letters, digits, dash and underscore.', 'myotp-phone-verification' ) );
			}
		}
		return $clean;
	}

	/**
	 * Admin assets for our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_script( 'myotp-pv-admin', MYOTP_PV_URL . 'assets/js/myotp-admin.js', array(), MYOTP_PV_VERSION, true );
		wp_localize_script(
			'myotp-pv-admin',
			'myotpPvAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'myotp_pv_admin' ),
				'i18n'    => array(
					'sending'   => __( 'Sending...', 'myotp-phone-verification' ),
					'network'   => __( 'Network error. Try again.', 'myotp-phone-verification' ),
					'needPhone' => __( 'Enter a phone number first.', 'myotp-phone-verification' ),
					'unsaved'   => __( 'Save the settings first, then send the test.', 'myotp-phone-verification' ),
				),
			)
		);
		wp_enqueue_style( 'myotp-pv', MYOTP_PV_URL . 'assets/css/myotp-verify.css', array(), MYOTP_PV_VERSION );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = myotp_pv_get_options();
		$has_wc = class_exists( 'WooCommerce' );
		$name   = MYOTP_PV_OPTION;
		?>
		<div class="wrap myotp-pv-admin">
			<h1><?php esc_html_e( 'MyOTP Phone Verification', 'myotp-phone-verification' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: link to myotp.app sign-up */
					esc_html__( 'Get an API key at %s (15 free trial credits, no card). Dashboard: User API Keys.', 'myotp-phone-verification' ),
					'<a href="https://myotp.app/sign-up/" target="_blank" rel="noopener">myotp.app/sign-up</a>'
				);
				?>
			</p>
			<?php settings_errors( 'myotp_pv' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'myotp_pv' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="myotp_pv_api_key"><?php esc_html_e( 'API key', 'myotp-phone-verification' ); ?></label></th>
						<td>
							<input type="password" id="myotp_pv_api_key" class="regular-text code" autocomplete="off"
								name="<?php echo esc_attr( $name ); ?>[api_key]"
								value="<?php echo esc_attr( myotp_pv_mask_key( $o['api_key'] ) ); ?>" />
							<p class="description">
								<?php
								echo '' === $o['api_key']
									? esc_html__( 'No key saved yet.', 'myotp-phone-verification' )
									: esc_html__( 'A key is saved. Leave the stars in place to keep it, or paste a new key. Clear the field to remove it.', 'myotp-phone-verification' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="myotp_pv_channel"><?php esc_html_e( 'Channel', 'myotp-phone-verification' ); ?></label></th>
						<td>
							<select id="myotp_pv_channel" name="<?php echo esc_attr( $name ); ?>[channel]">
								<?php
								foreach ( array(
									'sms'      => 'SMS',
									'whatsapp' => 'WhatsApp',
									'telegram' => 'Telegram',
								) as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $o['channel'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="myotp_pv_otp_length"><?php esc_html_e( 'Code length', 'myotp-phone-verification' ); ?></label></th>
						<td>
							<input type="number" id="myotp_pv_otp_length" min="4" max="8" step="1" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[otp_length]" value="<?php echo esc_attr( $o['otp_length'] ); ?>" />
							<p class="description"><?php esc_html_e( '4 to 8 digits.', 'myotp-phone-verification' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="myotp_pv_otp_validity"><?php esc_html_e( 'Code validity (seconds)', 'myotp-phone-verification' ); ?></label></th>
						<td>
							<input type="number" id="myotp_pv_otp_validity" min="60" max="86400" step="1" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[otp_validity]" value="<?php echo esc_attr( $o['otp_validity'] ); ?>" />
							<p class="description"><?php esc_html_e( '60 to 86400. Applies to SMS.', 'myotp-phone-verification' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="myotp_pv_brand"><?php esc_html_e( 'Brand (optional)', 'myotp-phone-verification' ); ?></label></th>
						<td>
							<input type="text" id="myotp_pv_brand" class="regular-text" maxlength="16"
								name="<?php echo esc_attr( $name ); ?>[brand]" value="<?php echo esc_attr( $o['brand'] ); ?>" />
							<p class="description"><?php esc_html_e( '3 to 16 letters, digits or dots, shown in the message. Leave empty to use your account default.', 'myotp-phone-verification' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="myotp_pv_site_hourly_cap"><?php esc_html_e( 'Site-wide sends per hour', 'myotp-phone-verification' ); ?></label></th>
						<td>
							<input type="number" id="myotp_pv_site_hourly_cap" min="1" max="100000" step="1" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[site_hourly_cap]" value="<?php echo esc_attr( $o['site_hourly_cap'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Ceiling on codes sent by the whole site in any hour, on top of the per-visitor, per-IP and per-number limits. Behind a reverse proxy the per-IP limit may see only the proxy address, so this is the real backstop. Filter: myotp_pv_site_hourly_cap.', 'myotp-phone-verification' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce checkout', 'myotp-phone-verification' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[wc_enabled]" value="1" <?php checked( $o['wc_enabled'] ); ?> <?php disabled( ! $has_wc ); ?> />
									<?php esc_html_e( 'Require a verified billing phone at checkout', 'myotp-phone-verification' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[wc_guests_only]" value="1" <?php checked( $o['wc_guests_only'] ); ?> <?php disabled( ! $has_wc ); ?> />
									<?php esc_html_e( 'Only for guests (skip logged-in customers)', 'myotp-phone-verification' ); ?>
								</label>
								<?php if ( ! $has_wc ) : ?>
									<p class="description"><?php esc_html_e( 'WooCommerce is not active.', 'myotp-phone-verification' ); ?></p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Works with the classic (shortcode) checkout. The block checkout is not supported in this version.', 'myotp-phone-verification' ); ?></p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress registration', 'myotp-phone-verification' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[register_enabled]" value="1" <?php checked( $o['register_enabled'] ); ?> />
								<?php esc_html_e( 'Add a verified phone field to wp-login.php?action=register', 'myotp-phone-verification' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Send a test code', 'myotp-phone-verification' ); ?></h2>
			<p><?php esc_html_e( 'Uses the saved settings above. Each test costs credits like a real send.', 'myotp-phone-verification' ); ?></p>
			<p>
				<input type="tel" id="myotp_pv_test_phone" class="regular-text" placeholder="14155551234" inputmode="tel" />
				<button type="button" class="button button-secondary" id="myotp_pv_test_send" <?php disabled( '' === $o['api_key'] ); ?>>
					<?php esc_html_e( 'Send test code', 'myotp-phone-verification' ); ?>
				</button>
			</p>
			<p id="myotp_pv_test_result" class="myotp-pv-status" role="status" aria-live="polite"></p>

			<h2><?php esc_html_e( 'Shortcode', 'myotp-phone-verification' ); ?></h2>
			<p><code>[myotp_verify]</code> <?php esc_html_e( 'renders the widget on any page. On success it fires a myotp:verified event on document with the number in event.detail.phone.', 'myotp-phone-verification' ); ?></p>
		</div>
		<?php
	}
}
