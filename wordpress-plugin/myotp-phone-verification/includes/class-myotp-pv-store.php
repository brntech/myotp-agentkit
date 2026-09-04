<?php
/**
 * Atomic key/value store on the options table. add() is an INSERT that
 * fails on a duplicate name, cas() is an UPDATE guarded by the previous
 * value, so two concurrent requests cannot both win the same slot.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options-table store with add and compare-and-swap.
 */
class MyOTP_PV_Store {

	const PREFIX = 'myotp_pv_kv_';

	/**
	 * Replaceable instance (tests inject a memory store).
	 *
	 * @var object|null
	 */
	public static $instance = null;

	/**
	 * Get the shared store.
	 *
	 * @return object
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Row name for a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function name( $key ) {
		return self::PREFIX . md5( $key );
	}

	/**
	 * Wrap a raw value with its expiry.
	 *
	 * @param string $raw Raw value.
	 * @param int    $ttl Seconds.
	 * @return string
	 */
	private function wrap( $raw, $ttl ) {
		return (string) ( time() + max( 1, (int) $ttl ) ) . '|' . $raw;
	}

	/**
	 * Read the stored envelope, or null when missing or expired.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	private function envelope( $key ) {
		global $wpdb;
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $this->name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null === $row ) {
			return null;
		}
		$pos = strpos( $row, '|' );
		if ( false === $pos || (int) substr( $row, 0, $pos ) <= time() ) {
			$this->delete( $key );
			return null;
		}
		return $row;
	}

	/**
	 * Read a raw value.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function get( $key ) {
		$env = $this->envelope( $key );
		return null === $env ? null : substr( $env, strpos( $env, '|' ) + 1 );
	}

	/**
	 * Insert only when absent.
	 *
	 * @param string $key Key.
	 * @param string $raw Raw value.
	 * @param int    $ttl Seconds.
	 * @return bool
	 */
	public function add( $key, $raw, $ttl ) {
		global $wpdb;
		$this->envelope( $key ); // Drops an expired row so the insert can win.
		$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $this->name( $key ), $this->wrap( $raw, $ttl ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $done;
	}

	/**
	 * Replace only when the stored raw value is still $expected.
	 *
	 * @param string $key      Key.
	 * @param string $expected Raw value expected.
	 * @param string $raw      New raw value.
	 * @param int    $ttl      Seconds.
	 * @return bool
	 */
	public function cas( $key, $expected, $raw, $ttl ) {
		global $wpdb;
		$env = $this->envelope( $key );
		if ( null === $env || substr( $env, strpos( $env, '|' ) + 1 ) !== $expected ) {
			return false;
		}
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $this->wrap( $raw, $ttl ), $this->name( $key ), $env ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $done;
	}

	/**
	 * Write unconditionally.
	 *
	 * @param string $key Key.
	 * @param string $raw Raw value.
	 * @param int    $ttl Seconds.
	 */
	public function set( $key, $raw, $ttl ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $this->name( $key ), $this->wrap( $raw, $ttl ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete a key.
	 *
	 * @param string $key Key.
	 */
	public function delete( $key ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $this->name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
