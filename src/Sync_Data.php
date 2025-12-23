<?php
/**
 * Trait Sync_Data
 *
 * @package juvo/as_processor
 */

namespace juvo\AS_Processor;

use Exception;
use juvo\AS_Processor\Entities\Sync_Data_Lock_Exception;
use juvo\AS_Processor\DB\Data_DB;

/**
 * Trait Sync_Data
 *
 * This trait provides functionality for managing synchronization data using WordPress transients.
 * It includes mechanisms for retrieving, setting, and updating synchronization data, while ensuring
 * proper locking and concurrency handling.
 * It also supports advanced merging and concatenating options for array-type synchronization data.
 */
trait Sync_Data {


	/**
	 * Sync Data Name.
	 * Each data key is stored in a separate transient with the scheme "$this->sync_data_name_$key".
	 * This property is to set the shared "$this->sync_data_name" part for all the data keys of a sync.
	 *
	 * @var string
	 */
	private string $sync_data_name;

	/**
	 * Returns the sync data from a transient
	 *
	 * @param string $key Key of the sync data to retrieve.
	 * @return mixed
	 */
	protected function get_sync_data( string $key ): mixed {
		return Data_DB::db()->get( $this->get_sync_data_name() . '_' . $key, 'data' );
	}

	/**
	 * Returns the currently set sync data name. Defaults to the sync group name.
	 * Since the name can be overwritten with the setter and the group name is retrieved from the "action_scheduler_before_execute"
	 *
	 * @return string
	 */
	public function get_sync_data_name(): string {
		// Set sync data key to the group name by default. Sequential Sync does not have a group name
		if ( empty( $this->sync_data_name ) && method_exists( $this, 'get_sync_group_name' ) ) {
			$this->sync_data_name = $this->get_sync_group_name();
			return $this->sync_data_name;
		}
		return $this->sync_data_name;
	}

	/**
	 * Set the name for synchronization data.
	 *
	 * This method assigns a custom name to the synchronization data property. Mostly used for sequential sync process.
	 *
	 * @param string $sync_data_name The name to be assigned to the synchronization data.
	 * @return void
	 */
	public function set_sync_data_name( string $sync_data_name ): void {
		$this->sync_data_name = $sync_data_name;
	}

	/**
	 * Updates synchronization data directly in the options table.
	 *
	 * @param string $key The key identifying the synchronization data to update.
	 * @param mixed  $updates The data to update the synchronization data with.
	 * @param bool   $deep_merge Optional. Whether to perform a deep merge of arrays. Default is false.
	 * @param bool   $concat_arrays Optional. Whether to concatenate arrays instead of overriding. Default is false.
	 * @param int    $expiration Optional. The expiration time (in seconds) for the updated data. Default is 6 hours.
	 *
	 * @return void
	 * @throws Exception If the maximum retry time is reached.
	 */
	protected function update_sync_data( string $key, mixed $updates, bool $deep_merge = false, bool $concat_arrays = false, int $expiration = HOUR_IN_SECONDS * 6 ): void {

		// Set lock
		$this->set_key_lock( $key, true );

		// Handle merging logic
		if ( $deep_merge || $concat_arrays ) {

			// Retrieve the current data.
			$current_data = $this->get_sync_data( $key );

			// If current data not initialized yet make it an array
			if ( ! $current_data ) {
				$current_data = array();
			}

			if ( is_array( $current_data ) && is_array( $updates ) ) {
				$updates = Helper::merge_arrays( $current_data, $updates, $deep_merge, $concat_arrays );
			}
		}

		$success = Data_DB::db()->replace( $this->get_sync_data_name() . '_' . $key, $updates, $expiration );
		if ( false === $success ) {
			throw new Exception(
			/* translators: 1: The key name of the sync data trying to update., 2: The last db error. */
				sprintf( esc_attr__( 'Failed to update sync data for key %1$s: %2$s', 'as-processor' ), esc_attr( $key ), esc_attr( Data_DB::db()->get_last_error() ) )
			);
		}

		// Release lock
		$this->set_key_lock( $key, false );
	}

	/**
	 * Normalize a lock key to ensure it doesn't exceed MySQL's 64-character limit.
	 *
	 * @param string $lock_key The lock key to normalize.
	 * @return string The normalized lock key (max 64 characters).
	 */
	protected function normalize_lock_key( string $lock_key ): string {
		// If lock key is short enough, use it directly (better for debugging)
		if ( strlen( $lock_key ) <= 64 ) {
			return $lock_key;
		}

		// For long keys, use MD5 hash with prefix for identification
		$hash = md5( $lock_key );
		return 'asp_lock_' . $hash; // 41 chars total, well under limit
	}

	/**
	 * Set a lock state for a specific key using MySQL GET_LOCK if available, or fallback to the current method.
	 *
	 * @param string $key The key for which the lock state is being set.
	 * @param bool   $state Determines whether to enable (true) or disable (false) the key lock.
	 * @return void
	 * @throws Sync_Data_Lock_Exception When the current lock is set by another process.
	 */
	protected function set_key_lock( string $key, bool $state ): void {
		$lock_key = $this->get_sync_data_name() . '_' . $key . '_lock';

		// Fallback to using transient-based locking if database locks are not supported
		if ( ! $this->supports_db_locks() ) {
			$this->set_option_lock( $lock_key, $state );
			return;
		}

		// Use database-based locking for better reliability when available
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;

		// Normalize lock key to prevent exceeding 64 chars
		$db_lock_key = $this->normalize_lock_key( $lock_key );

		if ( $state ) {
			// Try to acquire a lock using MySQL GET_LOCK
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $db_lock_key, apply_filters( 'asp/sync_data/max_wait_time', 5, $key ) ) );

			if ( ! $result ) {
				throw new Sync_Data_Lock_Exception(
				/* translators: 1: The name of the key for which is database lock is acquired. */
					sprintf( esc_attr__( 'Failed to acquire database lock for %s', 'as-processor' ), esc_attr( $lock_key ) )
				);
			}
		} else {
			// Release the lock using MySQL RELEASE_LOCK
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $db_lock_key ) );
		}
		// phpcs:enable
	}

	/**
	 * Fallback mechanism to set a data-based lock.
	 *
	 * @param string $lock_key The key for which the lock state is being set.
	 * @param bool   $state Determines whether to enable (true) or disable (false) the key lock.
	 * @return void
	 * @throws Sync_Data_Lock_Exception When the lock could not be acquired.
	 */
	protected function set_option_lock( string $lock_key, bool $state ): void {

		$delay           = 0.1;
		$total_wait_time = 0;

		do {
			try {
				$lock_content = (int) Data_DB::db()->get( $lock_key, 'data' );

				// Check if another process owns the lock
				if ( $lock_content && $this->action_id !== $lock_content ) {
					throw new Sync_Data_Lock_Exception(
						esc_attr( sprintf( 'Failed to acquire option lock for %s', $lock_key ) )
					);
				}

				$lock_ttl = apply_filters( 'asp/sync_data/lock_ttl', 5 * MINUTE_IN_SECONDS, $lock_key );

				// Set or clear the transient-based lock
				if ( $state ) {
					Data_DB::db()->replace( $lock_key, $this->action_id, $lock_ttl );
				} else {
					Data_DB::db()->replace( $lock_key, false, $lock_ttl );
				}
				return;
			} catch ( Sync_Data_Lock_Exception $e ) {
				// Add random jitter to the delay (8% jitter in both directions)
				$jitter = wp_rand( -80000, 80000 ) / 1000000; // Random jitter between -0.08s and +0.08s
				$delay  = $delay + $jitter;

				$this->log(
					sprintf(
						/* translators: 1: Exception message, 2: Number of seconds the process will wait till next retry. */
						esc_attr__( '%1$s. Next try in %2$s seconds.', 'as-processor' ),
						esc_attr( $e->getMessage() ),
						number_format( $delay, 2 )
					)
				);
				usleep( (int) ( $delay * 1000000 ) );

				$total_wait_time += $delay;
				$delay            = $delay * 1.4;
			}
		} while ( $total_wait_time < floatval( apply_filters( 'asp/sync_data/max_wait_time', 5, $lock_key, $total_wait_time ) ) );

		/* translators: 1: Key being locked, 2: Number of seconds the process waited for the lock release. */
		throw new Sync_Data_Lock_Exception( sprintf( esc_attr__( 'Failed to acquire option lock for "%1$s". Tried %2$s seconds.', 'as-processor' ), esc_attr( $lock_key ), number_format( $total_wait_time, 2 ) ) );
	}

	/**
	 * Check if the current database system supports native MySQL/MariaDB locks.
	 *
	 * @return bool True if the system supports MySQL/MariaDB-level locks, false otherwise.
	 */
	private function supports_db_locks(): bool {
		global $wpdb;

		// Temporary - This will be in wp-config.php once SQLite is merged in Core.
		if ( defined( 'DB_ENGINE' ) && 'mysql' !== DB_ENGINE ) {
			return false;
		}

		// Optionally check db_version (MariaDB versions begin with '10')
		$db_version = $wpdb->db_version();
		if ( str_contains( strtolower( $db_version ), 'mariadb' ) || version_compare( $db_version, '5.7', '>=' ) ) {
			return true; // MariaDB or MySQL version 5.7+ supports GET_LOCK
		}

		// Assume unfamiliar systems don't support DB locks
		return false;
	}
}
