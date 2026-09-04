<?php
/**
 * Remove plugin options on uninstall. User meta and order meta are kept:
 * they are the site's records, not ours.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'myotp_pv_options' );

// Per-visitor transients (rate limit windows, pending codes, verified numbers).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_myotp\\_pv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_myotp\\_pv\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
