<?php
/**
 * Plugin Name:       MyOTP Phone Verification
 * Plugin URI:        https://github.com/brntech/myotp-agentkit/tree/main/wordpress-plugin
 * Description:       Phone verification by SMS, WhatsApp or Telegram code for WooCommerce checkout, registration and the [myotp_verify] shortcode.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MyOTP.App
 * Author URI:        https://myotp.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       myotp-phone-verification
 * Domain Path:       /languages
 * WC requires at least: 6.0
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MYOTP_PV_VERSION', '1.1.0' );
define( 'MYOTP_PV_FILE', __FILE__ );
define( 'MYOTP_PV_DIR', plugin_dir_path( __FILE__ ) );
define( 'MYOTP_PV_URL', plugin_dir_url( __FILE__ ) );
define( 'MYOTP_PV_OPTION', 'myotp_pv_options' );
define( 'MYOTP_PV_API_BASE', 'https://api.myotp.app' );
define( 'MYOTP_PV_RATE_MAX', 5 );
define( 'MYOTP_PV_RATE_WINDOW', 600 );

require_once MYOTP_PV_DIR . 'includes/functions.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-store.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-api.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-session.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-widget.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-ajax.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-settings.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-shortcode.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-registration.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-woocommerce.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-order-verify.php';

/**
 * Read plugin options merged with defaults.
 *
 * @return array
 */
function myotp_pv_get_options() {
	$stored = get_option( MYOTP_PV_OPTION, array() );
	return array_merge( myotp_pv_default_options(), is_array( $stored ) ? $stored : array() );
}

/**
 * Express wallet detector. Returns the wallet type (apple_pay, google_pay,
 * link, amazon_pay, payment_request_api) when the chosen payment method is a
 * gateway that offers express wallets and the request carries that
 * gateway's wallet marker; empty string otherwise, and the checkout gate
 * then applies as usual. Sources for every marker: myotp_pv_express_wallet_defaults().
 *
 * Every field here comes from the shopper's browser. The gateway id is the
 * real guard: a marker sent with cash on delivery, bank transfer or any
 * gateway not in the map is ignored. A shopper who sends the marker with a
 * wallet gateway has still paid through that gateway.
 *
 * Filters:
 * - myotp_pv_express_wallet_gateways: gateway id => list of marker field names.
 * - myotp_pv_express_wallet_types: accepted wallet type values.
 * - myotp_pv_express_wallet_type: the final answer, with the payment method and fields.
 *
 * @param mixed $payment_method Gateway id from the request.
 * @param array $fields         Request fields (classic: $_POST; Store API: payment_data).
 * @return string
 */
function myotp_pv_express_wallet_type( $payment_method, array $fields ) {
	$defaults = myotp_pv_express_wallet_defaults();
	$gateways = apply_filters( 'myotp_pv_express_wallet_gateways', $defaults['gateways'] );
	$types    = apply_filters( 'myotp_pv_express_wallet_types', $defaults['types'] );
	$type     = myotp_pv_match_express_wallet( $payment_method, $fields, is_array( $gateways ) ? $gateways : array(), is_array( $types ) ? $types : array() );
	$type     = apply_filters( 'myotp_pv_express_wallet_type', $type, $payment_method, $fields );
	return is_string( $type ) ? sanitize_key( $type ) : '';
}

/**
 * True when the request is an express wallet order (see myotp_pv_express_wallet_type()).
 *
 * @param mixed $payment_method Gateway id from the request.
 * @param array $fields         Request fields.
 * @return bool
 */
function myotp_pv_is_express_wallet_request( $payment_method, array $fields ) {
	return '' !== myotp_pv_express_wallet_type( $payment_method, $fields );
}

/**
 * Marker field names across every configured wallet gateway, so the
 * classic checkout reads only those keys from the posted form.
 *
 * @return string[]
 */
function myotp_pv_express_marker_keys() {
	$defaults = myotp_pv_express_wallet_defaults();
	$gateways = apply_filters( 'myotp_pv_express_wallet_gateways', $defaults['gateways'] );
	$keys     = array();
	foreach ( is_array( $gateways ) ? $gateways : array() as $markers ) {
		foreach ( is_array( $markers ) ? $markers : array() as $marker ) {
			if ( is_string( $marker ) && '' !== $marker ) {
				$keys[ $marker ] = true;
			}
		}
	}
	return array_keys( $keys );
}

/**
 * Boot the plugin. Translations load on demand from WordPress.org
 * language packs (WordPress 4.6+), so the text domain is not loaded here.
 */
function myotp_pv_init() {
	MyOTP_PV_Widget::init();
	MyOTP_PV_Ajax::init();
	MyOTP_PV_Settings::init();
	MyOTP_PV_Shortcode::init();
	MyOTP_PV_Registration::init();
	if ( class_exists( 'WooCommerce' ) ) {
		MyOTP_PV_WooCommerce::init();
		// Always on with WooCommerce: orders held earlier must stay verifiable after a settings change.
		MyOTP_PV_Order_Verify::init();
	}
}
add_action( 'plugins_loaded', 'myotp_pv_init' );

/**
 * Declare compatibility with WooCommerce order storage. The plugin never
 * touches orders directly.
 */
function myotp_pv_declare_wc_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MYOTP_PV_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'myotp_pv_declare_wc_compat' );

/**
 * Suggested text for the site's privacy policy (Settings > Privacy).
 */
function myotp_pv_privacy_policy() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for sites using MyOTP Phone Verification.', 'myotp-phone-verification' ) . '</p>'
		. '<p>' . esc_html__( 'When you verify a phone number on this site, the number is sent to MyOTP.App (api.myotp.app) so a one-time code can be delivered by SMS, WhatsApp or Telegram and checked. MyOTP.App processes the number under its own privacy policy and terms: https://myotp.app/privacy-policy/ and https://myotp.app/term-condition/.', 'myotp-phone-verification' ) . '</p>'
		. '<p>' . esc_html__( 'This site sets a cookie named myotp_pv_sid for one day to tie your verification to your browser. It keeps a record of the number, the code reference and the number of attempts for as long as the code is valid (the site setting, at most 4 hours), and after five wrong codes a 15-minute cooldown for your browser on that number. A verified number stays attached to your session for up to 30 minutes. Expired records are removed by a daily clean-up.', 'myotp-phone-verification' ) . '</p>'
		. '<p>' . esc_html__( 'If you register an account, the verified number is stored in your user profile. If you place an order, it is stored with the order. Both stay with your account or order data until they are deleted.', 'myotp-phone-verification' ) . '</p>';
	wp_add_privacy_policy_content( __( 'MyOTP Phone Verification', 'myotp-phone-verification' ), wp_kses_post( $content ) );
}
add_action( 'admin_init', 'myotp_pv_privacy_policy' );

/**
 * Daily sweep of expired store rows (counters, pending codes, verified
 * records, phone locks). Deletes in batches of 1000, at most 20 per run;
 * when the last batch was full a single continuation is scheduled five
 * minutes later on the same hook.
 */
function myotp_pv_sweep() {
	$result = MyOTP_PV_Store::instance()->sweep_expired( 1000, 20 );
	if ( ! empty( $result['full'] ) ) {
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'myotp_pv_sweep' );
	}
}
add_action( 'myotp_pv_sweep', 'myotp_pv_sweep' );

/**
 * Make sure the daily sweep is scheduled.
 */
function myotp_pv_schedule_sweep() {
	if ( ! wp_next_scheduled( 'myotp_pv_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'myotp_pv_sweep' );
	}
}
add_action( 'init', 'myotp_pv_schedule_sweep' );

/**
 * Seed defaults and schedule the sweep on activation.
 */
function myotp_pv_activate() {
	if ( false === get_option( MYOTP_PV_OPTION, false ) ) {
		add_option( MYOTP_PV_OPTION, myotp_pv_default_options() );
	}
	myotp_pv_schedule_sweep();
}
register_activation_hook( MYOTP_PV_FILE, 'myotp_pv_activate' );

/**
 * Unschedule the sweep on deactivation.
 */
function myotp_pv_deactivate() {
	wp_clear_scheduled_hook( 'myotp_pv_sweep' );
}
register_deactivation_hook( MYOTP_PV_FILE, 'myotp_pv_deactivate' );
