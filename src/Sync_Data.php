<?php
/**
 * Trait Sync_Data
 *
 * @package juvo/as_processor
 */

namespace juvo\AS_Processor;

use Exception;
use juvo\AS_Processor\Entities\Sync_Data_Lock_Exception;

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
		$transient = $this->get_option( $this->get_sync_data_name() . '_' . $key );

		// Return false if there's no data
		if ( empty( $transient ) ) {
			return false;
		}

		return $transient;
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

		$delay           = 0.1; // Initial delay in seconds
		$total_wait_time = 0;

		do {
			try {
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

				// Save the updated data back into the transient.
				$this->update_option( $this->get_sync_data_name() . '_' . $key, $updates, $expiration );

				// Release lock
				$this->set_key_lock( $key, false );

				return;
			} catch ( Sync_Data_Lock_Exception $e ) {
				// Add random jitter to the delay (8% jitter in both directions)
				$jitter = wp_rand( -80000, 80000 ) / 1000000; // Random jitter between -0.08s and +0.08s
				$delay  = $delay + $jitter;

				$this->log(
					sprintf(
					/* translators: 1: Exception message, 2: Number of seconds the process will wait till next retry. */
						esc_attr__( '%1$s Next try in %2$s seconds.', 'as-processor' ),
						esc_attr( $e->getMessage() ),
						number_format( $delay, 2 )
					)
				);
				usleep( (int) $delay * 1000000 );

				$total_wait_time += $delay;
			}
		} while ( $total_wait_time < floatval( apply_filters( 'asp/sync_data/max_wait_time', 5, $key, $total_wait_time ) ) );

		/* translators: 1: Key being locked, 2: Number of seconds the process waited for the lock release. */
		throw new Exception( sprintf( esc_attr__( 'Failed to update sync data "%1$s". Tried %2$s seconds.', 'as-processor' ), esc_attr( $key ), number_format( $total_wait_time, 2 ) ) );
	}

	/**
	 * Set a lock state for a specific key with an optional expiration (TTL).
	 *
	 * @param string $key The key for which the lock state is being set.
	 * @param bool   $state Determines whether to enable (true) or disable (false) the key lock.
	 * @return void
	 * @throws Sync_Data_Lock_Exception When the current lock is set by another process.
	 */
	protected function set_key_lock( string $key, bool $state ): void {
		$lock_key     = $this->get_sync_data_name() . '_' . $key . '_lock';
		$lock_content = $this->get_option( $lock_key );

		if ( $lock_content && getmypid() !== $lock_content ) {
			throw new Sync_Data_Lock_Exception( esc_attr( sprintf( 'Another process owns the lock for %s', $lock_key ) ) );
		}

		$lock_ttl = apply_filters( 'asp/sync_data/lock_ttl', 5 * MINUTE_IN_SECONDS, $key );

		if ( $state ) {
			// Setting the lock with the pid
			$this->update_option( $lock_key, getmypid(), $lock_ttl );
		} else {
			$this->update_option( $lock_key, false, $lock_ttl );
		}
	}

	/**
	 * Get the most recent option value
	 *
	 * Due to the nature of options and how WordPress handels object caching, this wrapper is needed to always get
	 * the most recent value from the cache.
	 *
	 * WordPress caches transients and options if no external object cache is used.
	 * These caches are also deleted before querying the new db value.
	 *
	 * When an external object cache is used a forced wp_cache_get is used.
	 *
	 * @param string $key Key of the transient to get.
	 * @link https://github.com/rhubarbgroup/redis-cache/issues/523
	 */
	private function get_option( string $key ) {
		if ( ! str_contains( $key, 'asp_' ) ) {
			$key = 'asp_' . $key;
		}

		if ( ! wp_using_ext_object_cache() ) {

			// Delete transient cache
			wp_cache_delete( $key, 'options' );
			$data = get_option( $key );
		} else {
			$data = wp_cache_get( $key, 'transient', true );
		}

		if ( empty( $data['value'] ) || ( isset( $data['timestamp'] ) && $data['timestamp'] < time() ) ) {
			return false;
		}

		return $data['value'];
	}

	/**
	 * Updates an option in the options table with a new value and timestamp.
	 *
	 * @param string $key The option name or key to update.
	 * @param mixed  $value The new value to be stored.
	 * @param int    $timestamp The timestamp offset to be added to the current time.
	 * @return bool True if the option value was successfully updated, false otherwise.
	 */
	protected function update_option( string $key, mixed $value, int $timestamp ): bool {
		if ( ! str_contains( $key, 'asp_' ) ) {
			$key = 'asp_' . $key;
		}

		return update_option(
			$key,
			array(
				'timestamp' => time() + $timestamp,
				'value'     => $value,
			),
			false
		);
	}

	/**
	 * Cleans up synchronization-related data from the options table.
	 *
	 * This method removes or processes options from the database that are associated with a specific synchronization prefix or group.
	 * It either deletes matching groups or tries to get the value what automatically checks if the time limit is reached and deletes the option when needed.
	 *
	 * @param string $force_delete_group Optional. A group identifier to forcefully delete matching options. Defaults to an empty string.
	 * @return void
	 */
	public function cleanup_sync_data( string $force_delete_group = '' ): void {
		global $wpdb;

		// Query options table for keys matching the pattern.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
           SELECT option_name 
           FROM {$wpdb->options} 
           WHERE option_name LIKE %s",
				'asp_%' // sanitize the LIKE pattern
			),
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$option_name = $row['option_name'];

			// Maybe force delete option
			if ( ! empty( $force_delete_group ) && str_contains( $option_name, $force_delete_group ) ) {
				delete_option( $option_name );
			}

			if ( ! $this->get_option( $option_name ) ) {
				delete_option( $option_name );
			}
		}
	}
}
