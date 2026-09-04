<?php
/**
 * Plugin Name:       MyOTP Phone Verification
 * Plugin URI:        https://github.com/brntech/myotp-agentkit/tree/main/wordpress-plugin
 * Description:       Phone verification by SMS, WhatsApp or Telegram OTP for WooCommerce checkout, WordPress registration and any page via the [myotp_verify] shortcode.
 * Version:           1.0.0
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

define( 'MYOTP_PV_VERSION', '1.0.0' );
define( 'MYOTP_PV_FILE', __FILE__ );
define( 'MYOTP_PV_DIR', plugin_dir_path( __FILE__ ) );
define( 'MYOTP_PV_URL', plugin_dir_url( __FILE__ ) );
define( 'MYOTP_PV_OPTION', 'myotp_pv_options' );
define( 'MYOTP_PV_API_BASE', 'https://api.myotp.app' );
define( 'MYOTP_PV_RATE_MAX', 5 );
define( 'MYOTP_PV_RATE_WINDOW', 600 );

require_once MYOTP_PV_DIR . 'includes/functions.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-api.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-session.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-widget.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-ajax.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-settings.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-shortcode.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-registration.php';
require_once MYOTP_PV_DIR . 'includes/class-myotp-pv-woocommerce.php';

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
 * Boot the plugin.
 */
function myotp_pv_init() {
	load_plugin_textdomain( 'myotp-phone-verification', false, dirname( plugin_basename( MYOTP_PV_FILE ) ) . '/languages' );

	MyOTP_PV_Widget::init();
	MyOTP_PV_Ajax::init();
	MyOTP_PV_Settings::init();
	MyOTP_PV_Shortcode::init();
	MyOTP_PV_Registration::init();
	if ( class_exists( 'WooCommerce' ) ) {
		MyOTP_PV_WooCommerce::init();
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
 * Seed defaults on activation.
 */
function myotp_pv_activate() {
	if ( false === get_option( MYOTP_PV_OPTION, false ) ) {
		add_option( MYOTP_PV_OPTION, myotp_pv_default_options() );
	}
}
register_activation_hook( MYOTP_PV_FILE, 'myotp_pv_activate' );
