<?php

namespace juvo\AS_Processor;

use Exception;

trait Sync_Data
{

    private string $sync_data_name;
    private bool $locked_by_current_process = false;

    /**
     * Returns the sync data from a transient
     *
     * @param string $key
     * @return mixed
     * @throws \Exception
     */
    protected function get_sync_data(string $key = ""): mixed
    {

        $attempts = 0;
        do {
            try {

                if ($this->is_locked() && !$this->locked_by_current_process) {
                    throw new \Exception('Sync Data is locked');
                }

                $transient = $this->get_transient($this->get_sync_data_name());
                if (empty($transient)) {
                    return false;
                }

                if (empty($key)) {
                    return $transient;
                }

                if (isset($transient[$key])) {
                    return $transient[$key];
                }

                return false;
            } catch (Exception $e) {
                $attempts++;
                sleep(1);
                continue;
            }
        } while($attempts < 5);

        $attempts = $attempts +1; // Adjust counting for final error
        throw new \Exception("Sync Data is locked. Tried {$attempts} times");
    }

    /**
     * Attempts to acquire a lock and returns true if successful.
     *
     * @throws Exception If the lock is already acquired.
     *
     * @return bool True if the lock was acquired, false otherwise.
     */
    protected function acquire(int $lock_ttl = 5*MINUTE_IN_SECONDS): bool
    {
        $lock = $this->get_transient( $this->get_sync_data_name() . '_lock' );
        if ($lock) {
            throw new Exception( 'Lock is already acquired' );
        }
        set_transient( $this->get_sync_data_name() . '_lock', true, $lock_ttl );
        $this->locked_by_current_process = true;
        return true;
    }

    /**
     * Releases a lock that was previously acquired.
     *
     * @return void
     */
    protected function release(): void
    {
        delete_transient( $this->get_sync_data_name() . '_lock' );
        $this->locked_by_current_process = false;
    }

    /**
     * Checks if a lock is currently held.
     *
     * @return bool True if the lock is held, false otherwise.
     */
    protected function is_locked(): bool
    {
        if ($this->locked_by_current_process) {
            return true;
        }

        return (bool) $this->get_transient( $this->get_sync_data_name() . '_lock' );
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
     * @param array $data
     * @param int $expiration
     * @return void
     * @throws Exception
     */
    protected function set_sync_data(array $data, int $expiration = HOUR_IN_SECONDS * 6): void
    {
        if ($this->is_locked() && !$this->locked_by_current_process) {
            throw new \Exception('Sync Data is locked');
        }

        set_transient($this->get_sync_data_name(), $data, $expiration);
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
     * @param array $updates Associative array of data to update.
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
    protected function update_sync_data(array $updates,bool $deepMerge = false, bool $concatArrays = false, int $expiration = HOUR_IN_SECONDS * 6): void
    {

        $attempts = 0;

        // Update sync data
        do {
            try {
                // Lock data first
                $this->acquire();

                // Retrieve the current transient data.
                $currentData = $this->get_sync_data();

                // If there's no existing data, treat it as an empty array.
                if (!is_array($currentData)) {
                    $currentData = [];
                }

                // Merge the new updates into the current data, respecting the deepMerge and concatArrays flags.
                $newData = $this->mergeArrays($currentData, $updates, $deepMerge, $concatArrays);

                // Save the updated data back into the transient.
                $this->set_sync_data($newData, $expiration);

                // Unlock
                $this->release();
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
     * Merges two arrays with options for deep merging and array concatenation.
     *
     * @param array $array1 The original array.
     * @param array $array2 The array to merge into the original array.
     * @param bool $deepMerge Optional. Flag to control deep merging. Default is true.
     * @param bool $concatArrays Optional. Flag to control array concatenation. Default is false.
     * @return array The merged array.
     */
    private function mergeArrays(array $array1, array $array2, bool $deepMerge = true, bool $concatArrays = false): array
    {
        foreach ($array2 as $key => $value) {
            if (!isset($array1[$key]) || (!is_array($value) && !is_array($array1[$key]))) {
                // If the key doesn't exist in array1 or either value is not an array, simply use the value from array2
                $array1[$key] = $value;
            } elseif (is_array($value) && is_array($array1[$key])) {
                // Both values are arrays, merge them based on the merge strategy
                $array1[$key] = $this->mergeArrayValues($array1[$key], $value, $deepMerge, $concatArrays);
            } else {
                // If types don't match (one is array, the other is not), use the value from array2
                $array1[$key] = $value;
            }
        }

        return $array1;
    }

    /**
     * Merges two array values based on the merge strategy.
     *
     * @param array $value1 The original array value.
     * @param array $value2 The array value to merge into the original.
     * @param bool $deepMerge Flag to control deep merging.
     * @param bool $concatArrays Flag to control array concatenation.
     * @return array The merged array value.
     */
    private function mergeArrayValues(array $value1, array $value2, bool $deepMerge, bool $concatArrays): array
    {
        $bothIndexed = $this->isIndexedArray($value1) && $this->isIndexedArray($value2);

        if (!$deepMerge) {
            return $this->shallowMerge($value1, $value2, $bothIndexed, $concatArrays);
        }

        if ($bothIndexed) {
            return $concatArrays ? array_merge($value1, $value2) : $value2;
        }

        return $this->mergeArrays($value1, $value2, true, $concatArrays);
    }

    /**
     * Performs a shallow merge of two arrays.
     *
     * @param array $value1 The original array value.
     * @param array $value2 The array value to merge into the original.
     * @param bool $bothIndexed Whether both arrays are indexed.
     * @param bool $concatArrays Flag to control array concatenation.
     * @return array The shallow-merged array.
     */
    private function shallowMerge(array $value1, array $value2, bool $bothIndexed, bool $concatArrays): array
    {
        if ($bothIndexed) {
            return $concatArrays ? array_merge($value1, $value2) : $value2;
        }

        // For associative arrays, merge at the top level
        return $value2 + $value1;
    }

    /**
     * Checks if an array is an indexed array (not associative).
     *
     * @param array $array The array to check.
     * @return bool True if the array is indexed, false otherwise.
     */
    private function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return true; // Consider empty arrays as indexed
        }
        return array_keys($array) === range(0, count($array) - 1);
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
        delete_transient($this->get_sync_data_name());
    }

}
