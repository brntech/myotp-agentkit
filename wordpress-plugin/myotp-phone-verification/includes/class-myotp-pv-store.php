<?php
/**
 * Atomic key/value store on the options table. add() is an INSERT that
 * fails on a duplicate name, cas() is an UPDATE guarded by the previous
 * value, delete() is guarded by the previous value when one is given, so
 * two concurrent requests cannot both win the same slot and a stale
 * request cannot remove a row another request just wrote. All value
 * comparisons are BINARY so message ids compare byte for byte.
 *
 * Row value layout: "<expiry unix time>|<raw value>". The expiry is a
 * fixed-width, zero-padded prefix so the sweeper can compare it as text.
 *
 * @package myotp-phone-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options-table store with add, compare-and-swap and guarded delete.
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
		return sprintf( '%012d', time() + max( 1, (int) $ttl ) ) . '|' . $raw;
	}

	/**
	 * Split an envelope into expiry and raw value.
	 *
	 * @param string $env Envelope.
	 * @return array{0: int, 1: string}|null
	 */
	private function split( $env ) {
		$pos = strpos( (string) $env, '|' );
		if ( false === $pos ) {
			return null;
		}
		return array( (int) substr( $env, 0, $pos ), substr( $env, $pos + 1 ) );
	}

	/**
	 * Read the stored envelope, or null when missing or expired. An expired
	 * row is removed only if it still holds the value we read.
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
		$parts = $this->split( $row );
		if ( null === $parts || $parts[0] <= time() ) {
			$this->delete_envelope( $key, $row );
			return null;
		}
		return $row;
	}

	/**
	 * Delete a row only while it still holds $env.
	 *
	 * @param string $key Key.
	 * @param string $env Envelope read earlier.
	 * @return bool
	 */
	private function delete_envelope( $key, $env ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = BINARY %s", $this->name( $key ), $env ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $done;
	}

	/**
	 * Read a raw value.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function get( $key ) {
		$env = $this->envelope( $key );
		return null === $env ? null : $this->split( $env )[1];
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
		$this->envelope( $key ); // Drops an expired row (guarded) so the insert can win.
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
		if ( null === $env || $this->split( $env )[1] !== $expected ) {
			return false;
		}
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = BINARY %s", $this->wrap( $raw, $ttl ), $this->name( $key ), $env ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
	 * Delete a key. With $expected_raw, delete only while the row still
	 * holds that raw value (any expiry).
	 *
	 * @param string      $key          Key.
	 * @param string|null $expected_raw Raw value the caller read, or null for unconditional.
	 * @return bool
	 */
	public function delete( $key, $expected_raw = null ) {
		global $wpdb;
		if ( null === $expected_raw ) {
			$done = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $this->name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1 === (int) $done;
		}
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $this->name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null === $row ) {
			return false;
		}
		$parts = $this->split( $row );
		if ( null === $parts || $parts[1] !== $expected_raw ) {
			return false;
		}
		return $this->delete_envelope( $key, $row );
	}

	/**
	 * Remove expired rows with set-based deletes. The expiry is the leading
	 * 12 zero-padded digits of option_value, so a plain string comparison
	 * against "<now>|" selects every expired row without decoding. Runs
	 * up to $max_batches deletes of $batch rows; reports whether the last
	 * batch was full so the caller can schedule a continuation.
	 *
	 * @param int $batch       Rows per DELETE.
	 * @param int $max_batches Batches per call.
	 * @return array{removed: int, full: bool}
	 */
	public function sweep_expired( $batch = 1000, $max_batches = 20 ) {
		global $wpdb;
		$removed = 0;
		$full    = false;
		$like    = $wpdb->esc_like( self::PREFIX ) . '%';
		$cutoff  = sprintf( '%012d', time() ) . '|';
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$done     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < BINARY %s LIMIT %d", $like, $cutoff, (int) $batch ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$removed += $done;
			$full     = $done >= $batch;
			if ( ! $full ) {
				break;
			}
		}
		return array(
			'removed' => $removed,
			'full'    => $full,
		);
	}
}
