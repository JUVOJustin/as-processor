<?php
/**
 * Stores sync data below one explicit namespace.
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

use Exception;
use juvo\AS_Processor\DB\Data_DB;
use juvo\AS_Processor\Entities\Sync_Data_Lock_Exception;

/**
 * Data store for one sync-data namespace.
 *
 * A store writes keys as "{$name}_{$key}". Sync uses one run-scoped store for
 * lifecycle state, while Sequential_Sync can provide an additional shared store
 * for handoff data between child jobs.
 */
class Sync_Data_Store {

	/**
	 * Namespace prefix for all keys in this store.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Current Action Scheduler action ID used to own fallback locks.
	 *
	 * @var int
	 */
	private int $action_id = 0;

	/**
	 * Process-level owner token used before an action ID is available.
	 *
	 * @var string
	 */
	private string $owner_token;

	/**
	 * Create a store for one sync-data namespace.
	 *
	 * @param string $name Namespace prefix.
	 */
	public function __construct( string $name ) {
		$this->name        = $name;
		$this->owner_token = uniqid( 'asp_sync_data_', true );
	}

	/**
	 * Return the namespace prefix.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Change the namespace prefix.
	 *
	 * @param string $name Namespace prefix.
	 * @return void
	 */
	public function set_name( string $name ): void {
		$this->name = $name;
	}

	/**
	 * Set the Action Scheduler action ID that owns fallback locks.
	 *
	 * @param int $action_id Action ID.
	 * @return void
	 */
	public function set_action_id( int $action_id ): void {
		$this->action_id = $action_id;
	}

	/**
	 * Return one stored value from this namespace.
	 *
	 * @param string $key Key without namespace prefix.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		return Data_DB::db()->get( $this->get_storage_key( $key ), 'data' );
	}

	/**
	 * Update one value in this namespace.
	 *
	 * @param string $key Key without namespace prefix.
	 * @param mixed  $updates Value to write.
	 * @param bool   $deep_merge Whether to deep-merge arrays.
	 * @param bool   $concat_arrays Whether to concatenate arrays.
	 * @param int    $expiration Expiration in seconds.
	 * @return void
	 * @throws Exception When the value cannot be written.
	 */
	public function update( string $key, mixed $updates, bool $deep_merge = false, bool $concat_arrays = false, int $expiration = HOUR_IN_SECONDS * 6 ): void {
		$this->set_key_lock( $key, true );

		try {
			if ( $deep_merge || $concat_arrays ) {
				$current_data = $this->get( $key );

				if ( ! $current_data ) {
					$current_data = array();
				}

				if ( is_array( $current_data ) && is_array( $updates ) ) {
					$updates = Helper::merge_arrays( $current_data, $updates, $deep_merge, $concat_arrays );
				}
			}

			$this->replace_unlocked( $key, $updates, $expiration );
		} finally {
			$this->set_key_lock( $key, false );
		}
	}

	/**
	 * Write a value only when the key is not already set.
	 *
	 * @param string $key Key without namespace prefix.
	 * @param mixed  $value Value to write.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool True when this call wrote the value.
	 * @throws Exception When the value cannot be written.
	 */
	public function add_once( string $key, mixed $value, int $expiration = HOUR_IN_SECONDS * 6 ): bool {
		$this->set_key_lock( $key, true );

		try {
			if ( $this->get( $key ) ) {
				return false;
			}

			$this->replace_unlocked( $key, $value, $expiration );
		} finally {
			$this->set_key_lock( $key, false );
		}

		return true;
	}

	/**
	 * Normalize a lock key to fit MySQL's 64-character lock-name limit.
	 *
	 * @param string $lock_key Full lock key.
	 * @return string
	 */
	public static function normalize_lock_key( string $lock_key ): string {
		if ( strlen( $lock_key ) <= 64 ) {
			return $lock_key;
		}

		return 'asp_lock_' . md5( $lock_key );
	}

	/**
	 * Set a lock state for a key in this store.
	 *
	 * @param string $key Key without namespace prefix.
	 * @param bool   $state Whether to acquire or release the lock.
	 * @return void
	 * @throws Sync_Data_Lock_Exception When the lock cannot be acquired.
	 */
	public function set_key_lock( string $key, bool $state ): void {
		$lock_key = $this->get_storage_key( $key ) . '_lock';

		if ( ! $this->supports_db_locks() ) {
			$this->set_option_lock( $lock_key, $state );
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;

		$db_lock_key = self::normalize_lock_key( $lock_key );

		if ( $state ) {
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $db_lock_key, apply_filters( 'asp/sync_data/max_wait_time', 5, $key ) ) );

			if ( ! $result ) {
				throw new Sync_Data_Lock_Exception(
					sprintf(
						/* translators: 1: The name of the key for which a database lock is acquired. */
						esc_attr__( 'Failed to acquire database lock for %s', 'as-processor' ),
						esc_attr( $lock_key )
					)
				);
			}
		} else {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $db_lock_key ) );
		}
		// phpcs:enable
	}

	/**
	 * Return the database key for a key in this store.
	 *
	 * @param string $key Key without namespace prefix.
	 * @return string
	 */
	private function get_storage_key( string $key ): string {
		return $this->name . '_' . $key;
	}

	/**
	 * Replace a value while the caller already owns the key lock.
	 *
	 * @param string $key Key without namespace prefix.
	 * @param mixed  $value Value to write.
	 * @param int    $expiration Expiration in seconds.
	 * @return void
	 * @throws Exception When the value cannot be written.
	 */
	private function replace_unlocked( string $key, mixed $value, int $expiration ): void {
		$success = Data_DB::db()->replace( $this->get_storage_key( $key ), $value, $expiration );

		if ( false === $success ) {
			throw new Exception(
				sprintf(
					/* translators: 1: The key name of the sync data trying to update., 2: The last db error. */
					esc_attr__( 'Failed to update sync data for key %1$s: %2$s', 'as-processor' ),
					esc_attr( $key ),
					esc_attr( Data_DB::db()->get_last_error() )
				)
			);
		}
	}

	/**
	 * Fallback lock implementation for database engines without GET_LOCK.
	 *
	 * @param string $lock_key Full lock key.
	 * @param bool   $state Whether to acquire or release the lock.
	 * @return void
	 * @throws Sync_Data_Lock_Exception When the lock cannot be acquired.
	 */
	private function set_option_lock( string $lock_key, bool $state ): void {
		$delay           = 0.1;
		$total_wait_time = 0;
		$owner           = $this->get_lock_owner();

		do {
			try {
				$lock_content = (string) Data_DB::db()->get( $lock_key, 'data' );

				if ( $lock_content && $owner !== $lock_content ) {
					throw new Sync_Data_Lock_Exception(
						esc_attr( sprintf( 'Failed to acquire option lock for %s', $lock_key ) )
					);
				}

				$lock_ttl = apply_filters( 'asp/sync_data/lock_ttl', 5 * MINUTE_IN_SECONDS, $lock_key );

				if ( $state ) {
					Data_DB::db()->replace( $lock_key, $owner, $lock_ttl );
				} else {
					Data_DB::db()->replace( $lock_key, false, $lock_ttl );
				}
				return;
			} catch ( Sync_Data_Lock_Exception $e ) {
				$jitter = wp_rand( -80000, 80000 ) / 1000000;
				$delay  = $delay + $jitter;

				do_action( 'asp/sync_data/lock_retry', $lock_key, $e, $delay, $total_wait_time );

				usleep( (int) ( $delay * 1000000 ) );

				$total_wait_time += $delay;
				$delay            = $delay * 1.4;
			}
		} while ( $total_wait_time < floatval( apply_filters( 'asp/sync_data/max_wait_time', 5, $lock_key, $total_wait_time ) ) );

		throw new Sync_Data_Lock_Exception(
			sprintf(
				/* translators: 1: Key being locked, 2: Number of seconds waited for lock release. */
				esc_attr__( 'Failed to acquire option lock for "%1$s". Tried %2$s seconds.', 'as-processor' ),
				esc_attr( $lock_key ),
				number_format( $total_wait_time, 2 )
			)
		);
	}

	/**
	 * Return the owner value stored in fallback locks.
	 *
	 * @return string
	 */
	private function get_lock_owner(): string {
		if ( $this->action_id > 0 ) {
			return (string) $this->action_id;
		}

		return $this->owner_token;
	}

	/**
	 * Check whether the current database supports native locks.
	 *
	 * @return bool
	 */
	private function supports_db_locks(): bool {
		global $wpdb;

		if ( defined( 'DB_ENGINE' ) && 'mysql' !== DB_ENGINE ) {
			return false;
		}

		$db_version = $wpdb->db_version();
		if ( str_contains( strtolower( $db_version ), 'mariadb' ) || version_compare( $db_version, '5.7', '>=' ) ) {
			return true;
		}

		return false;
	}
}
