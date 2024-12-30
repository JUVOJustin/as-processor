<?php
/**
 * Trait Sync_Data
 *
 * @package juvo/as_processor
 */

namespace juvo\AS_Processor;

use Exception;

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
	 * List of data keys that are locked by the current process.
	 *
	 * @var string[]
	 */
	private array $locked_by_current_process = array();

	/**
	 * Returns the sync data from a transient
	 *
	 * @param string $key Key of the sync data to retrieve.
	 * @return mixed
	 * @throws Exception Thrown if the data is locked.
	 */
	protected function get_sync_data( string $key ): mixed {
		$attempts = 0;

		do {
			try {
				// Check if the specific key is locked and determine if the current process holds the lock
				if ( $this->is_locked( $key ) && ! $this->is_key_locked_by_current_process( $key ) ) {
					throw new Exception( esc_attr__( 'Sync Data is locked', 'as-processor' ) );
				}

				$transient = $this->get_transient( $this->get_sync_data_name() . '_' . $key );

				// Return false if there's no data
				if ( empty( $transient ) ) {
					return false;
				}

				return $transient;
			} catch ( Exception ) {
				++$attempts;
				sleep( 1 );
				continue;
			}
		} while ( $attempts < 5 );

		++$attempts; // Adjust counting for final error

		/* translators: Number of attempts */
		throw new Exception( sprintf( esc_attr__( 'Sync Data is locked. Tried %d times', 'as-processor' ), intval( $attempts ) ) );
	}

	/**
	 * Checks if a lock is currently held.
	 *
	 * @param string $key The key of the transient.
	 * @return bool True if the lock is held, false otherwise.
	 */
	protected function is_locked( string $key ): bool {
		return (bool) $this->get_transient( $this->get_sync_data_name() . '_' . $key . '_lock' );
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
	 * Stores data in a transient to be access in other jobs.
	 * This can be used e.g. to build a delta of post ids.
	 *
	 * Only sets the data if locked by itself.
	 *
	 * @param string $key The key where the data should be stored.
	 * @param mixed  $data The data that should be stored.
	 * @param int    $expiration The TTL of the data before WordPress´s transient handling is free to delete it.
	 * @return void
	 * @throws Exception Thrown if the data is locked.
	 */
	protected function set_sync_data( string $key, mixed $data, int $expiration = HOUR_IN_SECONDS * 6 ): void {
		if ( $this->is_locked( $key ) && ! $this->is_key_locked_by_current_process( $key ) ) {
			throw new Exception( 'Sync Data is locked' );
		}

		// Store the actual data
		set_transient( $this->get_sync_data_name() . '_' . $key, $data, $expiration );
	}

	/**
	 * Updates synchronization data with new values, applying optional merge strategies.
	 *
	 * This method retrieves the current data associated with the given key, applies the specified updates,
	 * and saves the modified data back with the defined expiration. The process respects specified merge
	 * and concatenation behaviors and includes a retry mechanism for ensuring successful execution.
	 *
	 * @param string $key The key identifying the synchronization data to update.
	 * @param mixed  $updates The data to update the synchronization data with.
	 * @param bool   $deep_merge Optional. Whether to perform a deep merge of arrays. Default is false.
	 * @param bool   $concat_arrays Optional. Whether to concatenate arrays instead of overriding. Default is false.
	 * @param int    $expiration Optional. The expiration time (in seconds) for the updated data. Default is 6 hours.
	 *
	 * @return void
	 *
	 * @throws Exception If the data update fails after multiple attempts.
	 */
	protected function update_sync_data( string $key, mixed $updates, bool $deep_merge = false, bool $concat_arrays = false, int $expiration = HOUR_IN_SECONDS * 6 ): void {

		$attempts = 0;

		// Update sync data
		do {
			try {

				// Lock data first
				if ( $this->is_locked( $key ) ) {
					throw new Exception( esc_attr__( 'Lock is already acquired', 'as-processor' ) );
				}
				$this->set_key_lock( $key, true );

				// Check if values is supposed to be an array
				if ( $deep_merge || $concat_arrays ) {

					// Retrieve the current transient data.
					$current_data = $this->get_sync_data( $key );

					// If current data not initialized yet make it an array
					if ( ! $current_data ) {
						$current_data = array();
					}

					// At this point if current data is an array and one of the merge options is used we can assume the update should also be an array to be merged
					if ( is_array( $current_data ) && ! is_array( $updates ) ) {
						$updates = array( $updates );
					}

					// Merge the new updates into the current data, respecting the deepMerge and concatArrays flags.
					if ( is_array( $current_data ) && is_array( $updates ) ) {
						$updates = Helper::merge_arrays( $current_data, $updates, $deep_merge, $concat_arrays );
					}
				}

				// Save the updated data back into the transient.
				$this->set_sync_data( $key, $updates, $expiration );

				// Unlock
				$this->set_key_lock( $key, false );
				return;
			} catch ( Exception $e ) {
				++$attempts;
				sleep( 1 );
				continue;
			}
		} while ( $attempts < 5 );

		// If this point is reached throw error
		/* translators: Number of attempts */
		throw new Exception( sprintf( esc_attr__( 'Failed to update sync data after %d tries', 'as-processor' ), intval( $attempts ) ) );
	}

	/**
	 * Get the most recent transient value
	 *
	 * Due to the nature of transients and how WordPress handels object caching, this wrapper is needed to always get
	 * the most recent value from the cache.
	 *
	 * WordPress caches transients in the options group if no external object cache is used.
	 * These caches are also deleted before querying the new db value.
	 *
	 * When an external object cache is used, the get_transient is avoided completely and a forced wp_cache_get is used.
	 *
	 * @param string $key Key of the transient to get.
	 * @link https://github.com/rhubarbgroup/redis-cache/issues/523
	 */
	private function get_transient( string $key ) {

		if ( ! wp_using_ext_object_cache() ) {

			// Delete transient cache
			$deletion_key = '_transient_' . $key;
			wp_cache_delete( $deletion_key, 'options' );

			// Delete timeout cache
			$deletion_key = '_transient_timeout_' . $key;
			wp_cache_delete( $deletion_key, 'options' );

			// At this point object cache is cleared and can be requested again
			$data = get_transient( $key );
		} else {
			$data = wp_cache_get( $key, 'transient', true );
		}

		return $data;
	}

	/**
	 * Fully deletes the sync data
	 *
	 * @return void
	 */
	public function delete_sync_data(): void {
		global $wpdb;

		// Define the base name of your transient
		$base_transient_name = $this->get_sync_data_name() . '_';

		// Prepare the like pattern for SQL, escaping wildcards and adding the wildcard placeholder
		$like_pattern = $wpdb->esc_like( '_transient_' . $base_transient_name ) . '%';

		// Use $wpdb to directly delete transients from the wp_options table
		$wpdb->query(
			$wpdb->prepare(
				"
                DELETE FROM $wpdb->options
                WHERE option_name LIKE %s
                ",
				$like_pattern
			)
		);
	}

	/**
	 * Determines if the given key is currently locked by the current process.
	 *
	 * This method checks if the specified key is marked as locked in the
	 * context of the current process.
	 *
	 * @param string $key The unique identifier for the lock.
	 * @return bool True if the key is locked by the current process, otherwise false.
	 */
	protected function is_key_locked_by_current_process( string $key ): bool {
		return isset( $this->locked_by_current_process[ $key ] ) && $this->locked_by_current_process[ $key ];
	}

	/**
	 * Set a lock state for a specific key with an optional time-to-live (TTL).
	 *
	 * This method updates the lock state for the specified key and sets a transient lock with a given TTL, if the state is true.
	 * The lock helps in synchronizing processes to prevent conflicts.
	 *
	 * @param string $key The key for which the lock state is being set.
	 * @param bool   $state Determines whether to enable (true) or disable (false) the key lock.
	 * @return void
	 */
	protected function set_key_lock( string $key, bool $state ): void {
		$this->locked_by_current_process[ $key ] = $state;

		if ( $state ) {
			// Allow the lock TTL to be filtered using a specific hook.
			$lock_ttl = apply_filters( 'asp/sync_data/lock_ttl', 5 * MINUTE_IN_SECONDS, $key );

			set_transient( $this->get_sync_data_name() . '_' . $key . '_lock', true, $lock_ttl );
		} else {
			delete_transient( $this->get_sync_data_name() . '_' . $key . '_lock' );
		}
	}
}
