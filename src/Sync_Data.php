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
		if ( empty( $this->sync_data_name ) ) {
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

		try {
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

			$this->write_or_throw( $this->get_sync_data_name() . '_' . $key, $updates, $expiration, $key );
		} finally {
			// Release lock even if the write fails so a single error does not block the key forever.
			$this->set_key_lock( $key, false );
		}
	}

	/**
	 * Write a value to the data store or throw when the write fails.
	 *
	 * @param string $storage_key Fully-qualified storage key including namespace.
	 * @param mixed  $value Value to write.
	 * @param int    $expiration Expiration time in seconds.
	 * @param string $key Bare key name, used only for the error message.
	 * @return void
	 * @throws Exception When the value cannot be written.
	 */
	private function write_or_throw( string $storage_key, mixed $value, int $expiration, string $key ): void {
		$success = Data_DB::db()->replace( $storage_key, $value, $expiration );

		if ( false === $success ) {
			throw new Exception(
			/* translators: 1: The key name of the sync data trying to update., 2: The last db error. */
				sprintf( esc_attr__( 'Failed to update sync data for key %1$s: %2$s', 'as-processor' ), esc_attr( $key ), esc_attr( Data_DB::db()->get_last_error() ) )
			);
		}
	}

	/**
	 * Atomically store a value only if the key has no value yet.
	 *
	 * The existence check and the write happen while the key lock is held, so
	 * concurrent Action Scheduler workers — for example the parallel chunk jobs
	 * that race to fire the finish hook once the last chunk completes — agree on
	 * a single winner. Exactly one caller receives `true`.
	 *
	 * Cross-process atomicity comes from the database lock (MySQL/MariaDB
	 * `GET_LOCK`). When native DB locks are unavailable the trait falls back to a
	 * transient-style lock that is not a strict cross-process mutex, so the
	 * single-winner guarantee is only firm on engines that support `GET_LOCK`.
	 *
	 * @param string      $key        Key without namespace prefix.
	 * @param mixed       $value      Value to store when the key is empty.
	 * @param string|null $data_name  Optional namespace override. Defaults to the sync-data name.
	 *                                Pass the sync group name to keep the key scoped to a single run.
	 * @param int         $expiration Optional. Expiration time in seconds. Default is 6 hours.
	 * @return bool True when this caller stored the value, false when it was already set.
	 * @throws Exception If the value cannot be written.
	 */
	protected function add_once( string $key, mixed $value, ?string $data_name = null, int $expiration = HOUR_IN_SECONDS * 6 ): bool {
		$data_name   = $data_name ?? $this->get_sync_data_name();
		$storage_key = $data_name . '_' . $key;
		$lock_key    = $storage_key . '_lock';

		$this->lock_by_name( $lock_key, true, $key );

		try {
			// Test for the row, not a truthy value: Data_DB::get( $key, 'data' )
			// returns false for a stored-but-falsy value (0, '', false, []), which
			// would otherwise let a falsy value be written more than once.
			if ( false !== Data_DB::db()->get( $storage_key ) ) {
				return false;
			}

			$this->write_or_throw( $storage_key, $value, $expiration, $key );

			return true;
		} finally {
			$this->lock_by_name( $lock_key, false, $key );
		}
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
		$this->lock_by_name( $this->get_sync_data_name() . '_' . $key . '_lock', $state, $key );
	}

	/**
	 * Acquire or release a lock by its fully-qualified lock name.
	 *
	 * Shared by set_key_lock() and add_once() so both go through the same
	 * MySQL GET_LOCK / transient-fallback path. The lock name already contains
	 * the namespace, which lets callers lock keys outside the default sync-data
	 * name (for example run-scoped finish tracking).
	 *
	 * @param string $lock_key Fully-qualified lock name including namespace and the `_lock` suffix.
	 * @param bool   $state    Whether to acquire (true) or release (false) the lock.
	 * @param string $key      Bare key name, used only for filter context and error messages.
	 * @return void
	 * @throws Sync_Data_Lock_Exception When the lock cannot be acquired.
	 */
	private function lock_by_name( string $lock_key, bool $state, string $key ): void {
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
