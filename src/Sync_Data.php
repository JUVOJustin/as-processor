<?php

namespace juvo\AS_Processor;

use Exception;

trait Sync_Data
{

    private string $sync_data_name;
    private array $locked_by_current_process = [];

    /**
     * Returns the sync data from a transient
     *
     * @param string $key
     * @return mixed
     * @throws \Exception
     */
    protected function get_sync_data(string $key): mixed {
        $attempts = 0;

        do {
            try {
                // Check if the specific key is locked and determine if the current process holds the lock
                if ($this->is_locked($key) && !$this->is_key_locked_by_current_process($key)) {
                    throw new \Exception('Sync Data is locked');
                }

                $transient = $this->get_transient($this->get_sync_data_name() . '_' . $key);

                // Return false if there's no data
                if (empty($transient)) {
                    return false;
                }

                if (is_array($transient) && 1 === count($transient)) {
                    return $transient[0];
                }

                return $transient;
            } catch (Exception $e) {
                $attempts++;
                sleep(1);
                continue;
            }
        } while ($attempts < 5);

        $attempts = $attempts + 1; // Adjust counting for final error
        throw new \Exception("Sync Data is locked. Tried {$attempts} times");
    }

    /**
     * Acquires a synchronization lock to ensure exclusive access.
     *
     * This method attempts to set a transient lock to synchronize certain operations.
     * If a lock already exists, it throws an exception to prevent concurrent access.
     *
     * @param string $key The unique identifier for the lock.
     * @param int $lock_ttl Optional. The time-to-live for the lock in seconds. Defaults to 5 minutes.
     * @return bool Returns true if the lock is successfully acquired.
     * @throws Exception If the lock is already acquired.
     */
    protected function acquire(string $key, int $lock_ttl = 5*MINUTE_IN_SECONDS): bool
    {
        $lock = $this->get_transient($this->get_sync_data_name() . '_' . $key . '_lock');
        if ($lock) {
            throw new Exception('Lock is already acquired');
        }
        set_transient($this->get_sync_data_name() . '_' . $key . '_lock', true, $lock_ttl);
        $this->set_key_lock($key, true);
        return true;
    }

    /**
     * Releases a lock that was previously acquired.
     *
     * @param string $key
     * @return void
     */
    protected function release( string $key ): void
    {
        delete_transient($this->get_sync_data_name() . '_' . $key . '_lock');
        $this->set_key_lock($key, false);
    }

    /**
     * Checks if a lock is currently held.
     *
     * @param string $key The key of the transient
     * @return bool True if the lock is held, false otherwise.
     */
    protected function is_locked(string $key): bool
    {
        return $this->is_key_locked_by_current_process($key) ||
            (bool) $this->get_transient($this->get_sync_data_name() . '_' . $key . '_lock');
    }

    /**
     * Returns the currently set sync data name. Defaults to the sync group name.
     * Since the name can be overwritten with the setter and the group name is retrieved from the "action_scheduler_before_execute"
     *
     * @return string
     */
    public function get_sync_data_name(): string
    {
        // Set sync data key to the group name by default. Sequential Sync does not have a group name
        if (empty($this->sync_data_name)  && method_exists($this, 'get_sync_group_name')) {
            $this->sync_data_name = $this->get_sync_group_name();
            return $this->sync_data_name;
        }
        return $this->sync_data_name;
    }

    public function set_sync_data_name(string $sync_data_name): void
    {
        $this->sync_data_name = $sync_data_name;
    }

    /**
     * Stores data in a transient to be access in other jobs.
     * This can be used e.g. to build a delta of post ids
     *
     * @param string $key
     * @param array $data
     * @param int $expiration
     * @return void
     * @throws Exception
     */
    protected function set_sync_data(string $key, array $data, int $expiration = HOUR_IN_SECONDS * 6): void
    {
        if ($this->is_locked($key) && !$this->locked_by_current_process) {
            throw new \Exception('Sync Data is locked');
        }

        // Store the actual data
        set_transient($this->get_sync_data_name() . '_' . $key, $data, $expiration);
    }

    /**
     * Updates parts of the transient data.
     *
     * This method updates the transient data by merging the provided updates into the current data.
     * It supports options for deep merging and array concatenation.
     *
     * Process:
     * - Acquires a lock to ensure data consistency.
     * - Retrieves the current transient data.
     * - Merges the updates into the current data based on the provided flags.
     * - Saves the updated data back into the transient storage.
     * - Releases the lock.
     *
     * If a lock is set a wait of 1 second is set. After 5 failed tries a final error is thrown
     *
     * @param string $key The key of the sync data transient
     * @param mixed $updates The data to update.
     * @param int $expiration Optional. Expiration time in seconds. Default is 6 hours.
     * @param bool $deepMerge Optional. Flag to control deep merging. Default is true.
     *                        - true: Recursively merge nested arrays.
     *                        - false: Replace nested arrays instead of merging.
     * @param bool $concatArrays Optional. Flag to control array concatenation. Default is false.
     *                           - true: Concatenate arrays instead of replacing.
     *                           - false: Replace arrays instead of concatenating.
     * @return void
     * @throws Exception
     */
    protected function update_sync_data(string $key, mixed $updates,bool $deepMerge = false, bool $concatArrays = false, int $expiration = HOUR_IN_SECONDS * 6): void
    {

        $attempts = 0;

        // Update sync data
        do {
            try {
                // Lock data first
                $this->acquire( $key );

                // Retrieve the current transient data.
                $currentData = $this->get_sync_data( $key );

                // If there's no existing data, treat it as an empty array.
                if (!is_array($currentData)) {
                    $currentData = [];
                }

                if (!is_array($updates)) {
                    $updates = [$updates];
                }

                // Merge the new updates into the current data, respecting the deepMerge and concatArrays flags.
                $newData = Helper::merge_arrays($currentData, $updates, $deepMerge, $concatArrays);

                // Save the updated data back into the transient.
                $this->set_sync_data($key, $newData, $expiration);

                // Unlock
                $this->release($key);
                return;
            } catch (Exception $e) {
                $attempts++;
                sleep(1);
                continue;
            }
        } while($attempts < 5);

        // If this point is reached throw error
        throw new \Exception("Failed to update sync data after $attempts tries");
    }

    /**
     * Get the most recent transient value
     *
     * Due to the nature of transients and how wordpress handels object caching, this wrapper is needed to always get
     * the most recent value from the cache.
     *
     * WordPress caches transients in the options group if no external object cache is used.
     * These caches are also deleted before querying the new db value.
     *
     * When an external object cache is used, the get_transient is avoided completely and a forced wp_cache_get is used.
     *
     * @link https://github.com/rhubarbgroup/redis-cache/issues/523
     */
    private function get_transient($key) {

        if (!wp_using_ext_object_cache()) {

            // Delete transient cache
            $deletion_key = '_transient_' . $key;
            wp_cache_delete($deletion_key, 'options');

            // Delete timeout cache
            $deletion_key = '_transient_timeout_' . $key;
            wp_cache_delete($deletion_key, 'options');

            // At this point object cache is cleared and can be requested again
            $data = get_transient($key);
        } else {
            $data = wp_cache_get($key, "transient", true);
        }

        return $data;
    }

    /**
     * Fully deletes the sync data
     *
     * @return void
     */
    public function delete_sync_data(): void
    {
        global $wpdb;

        // Define the base name of your transient
        $base_transient_name = $this->get_sync_data_name() . '_';

        // Prepare the like pattern for SQL, escaping wildcards and adding the wildcard placeholder
        $like_pattern = $wpdb->esc_like('_transient_' . $base_transient_name) . '%';

        // Use $wpdb to directly delete transients from the wp_options table
        $wpdb->query(
            $wpdb->prepare("
                DELETE FROM $wpdb->options
                WHERE option_name LIKE %s
                ", $like_pattern
            )
        );
    }

    protected function is_key_locked_by_current_process(string $key): bool
    {
        return isset($this->locked_by_current_process[$key]) && $this->locked_by_current_process[$key];
    }

    protected function set_key_lock(string $key, bool $state): void
    {
        $this->locked_by_current_process[$key] = $state;
    }
}
