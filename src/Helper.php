<?php

namespace juvo\AS_Processor;

use DateTimeImmutable;
use WP_Filesystem_Direct;

class Helper
{

	/**
	 * Retrieves an instance of the WP_Filesystem_Direct class.
	 *
	 * Ensures the WP_Filesystem_Direct class is loaded and returns its instance.
	 *
	 * @return \WP_Filesystem_Direct Returns an instance of the WP_Filesystem_Direct class.
	 */
	public static function get_direct_filesystem(): \WP_Filesystem_Direct {
		if (!class_exists( 'WP_Filesystem_Direct' )) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return new WP_Filesystem_Direct(null);
	}

    /**
     * Standardizes a file path to use forward slashes and removes redundant slashes.
     *
     * @param string $path The file path to be standardized.
     * 
     * @return string The standardized file path.
     */
    public static function normalize_path( string $path ): string
    {
        // set the directory sperator
        $separator = DIRECTORY_SEPARATOR;

        // Convert all backslashes to the OS-specific separator
        $path = str_replace('\\', $separator, $path);
    
        // Replace multiple consecutive separators with a single separator
        $path = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $path);
        
        return $path;
    }

	/**
	 * Converts a microtime float to a DateTimeImmutable
	 *
	 * @param float|null $microtime The microtime from microtime(true)
	 * @return DateTimeImmutable|null
	 * @throws \DateMalformedStringException
	 */
    public static function convert_microtime_to_datetime(?float $microtime): ?DateTimeImmutable
    {
        if (null === $microtime) {
            return null;
        }

        // Split into seconds and microseconds
        $seconds = (int) floor($microtime);
        $microseconds = (int) (($microtime - $seconds) * 1000000);

        return (new DateTimeImmutable())
            ->setTimestamp($seconds)
            ->modify("+{$microseconds} microseconds");
    }

    /**
     * Calculates the human-readable time difference in microseconds between two given timestamps.
     *
     * @param float $from The starting timestamp.
     * @param float $to The ending timestamp. If not provided, the current timestamp will be used.
     * @return string The human-readable time difference in microseconds.
     */
    public static function human_time_diff_microseconds( float $from, float $to = 0 ): string
    {
        if ( empty( $to ) ) {
            $to = microtime(true);
        }
        $diff = abs( $to - $from );

        $time_strings = array();

        if ( $diff < 1 ) { // Less than 1 second
            $total_microsecs = (int)($diff * 1000000);
            $millisecs = (int)($total_microsecs / 1000);
            $microsecs = $total_microsecs % 1000;

            if ( $millisecs > 0 ) {
                /* translators: Time difference in milliseconds */
                $time_strings[] = sprintf( _n( '%s millisecond', '%s milliseconds', $millisecs, 'as-processor' ), $millisecs );
            }
            if ( $microsecs > 0 ) {
                /* translators: Time difference in microseconds */
                $time_strings[] = sprintf( _n( '%s microsecond', '%s microseconds', $microsecs, 'as-processor' ), $microsecs );
            }
        } else {
            $remaining_seconds = $diff;

            $years = (int)($remaining_seconds / YEAR_IN_SECONDS);
            if ( $years > 0 ) {
                /* translators: Time difference in years */
                $time_strings[] = sprintf( _n( '%s year', '%s years', $years ), $years );
                $remaining_seconds -= $years * YEAR_IN_SECONDS;
            }

            $months = (int)($remaining_seconds / MONTH_IN_SECONDS);
            if ( $months > 0 ) {
                /* translators: Time difference in months */
                $time_strings[] = sprintf( _n( '%s month', '%s months', $months ), $months );
                $remaining_seconds -= $months * MONTH_IN_SECONDS;
            }

            $weeks = (int)($remaining_seconds / WEEK_IN_SECONDS);
            if ( $weeks > 0 ) {
                /* translators: Time difference in weeks */
                $time_strings[] = sprintf( _n( '%s week', '%s weeks', $weeks ), $weeks );
                $remaining_seconds -= $weeks * WEEK_IN_SECONDS;
            }

            $days = (int)($remaining_seconds / DAY_IN_SECONDS);
            if ( $days > 0 ) {
                /* translators: Time difference in days */
                $time_strings[] = sprintf( _n( '%s day', '%s days', $days ), $days );
                $remaining_seconds -= $days * DAY_IN_SECONDS;
            }

            $hours = (int)($remaining_seconds / HOUR_IN_SECONDS);
            if ( $hours > 0 ) {
                /* translators: Time difference in hours */
                $time_strings[] = sprintf( _n( '%s hour', '%s hours', $hours ), $hours );
                $remaining_seconds -= $hours * HOUR_IN_SECONDS;
            }

            $minutes = (int)($remaining_seconds / MINUTE_IN_SECONDS);
            if ( $minutes > 0 ) {
                /* translators: Time difference in minutes */
                $time_strings[] = sprintf( _n( '%s minute', '%s minutes', $minutes ), $minutes );
                $remaining_seconds -= $minutes * MINUTE_IN_SECONDS;
            }

            $seconds = (int)$remaining_seconds;
            if ( $seconds > 0 ) {
                /* translators: Time difference in seconds */
                $time_strings[] = sprintf( _n( '%s second', '%s seconds', $seconds ), $seconds );
                $remaining_seconds -= $seconds;
            }

            $milliseconds = (int)($remaining_seconds * 1000);
            if ( $milliseconds > 0 ) {
                /* translators: Time difference in milliseconds */
                $time_strings[] = sprintf( _n( '%s millisecond', '%s milliseconds', $milliseconds, 'as-processor' ), $milliseconds );
            }

            $microseconds = (int)($remaining_seconds * 1000000) - ($milliseconds * 1000);
            if ( $microseconds > 0 ) {
                /* translators: Time difference in microseconds */
                $time_strings[] = sprintf( _n( '%s microsecond', '%s microseconds', $microseconds, 'as-processor' ), $microseconds );
            }
        }

        // Join the time strings
        $separator = _x( ', ', 'Human time diff separator', 'as-processor' );
        return implode( $separator, $time_strings );
    }

    /**
     * Merges two arrays with options for deep merging and array concatenation.
     *
     * @param array $array1 The original array.
     * @param array $array2 The array to merge into the original array.
     * @param bool $deepMerge Optional. Flag to control deep merging. Default is false.
     * @param bool $concatArrays Optional. Flag to control array concatenation. Default is false.
     * @return array The merged array.
     */
    public static function merge_arrays(array $array1, array $array2, bool $deepMerge = false, bool $concatArrays = false): array
    {
        // Check if both arrays are flat
        if (self::is_flat_array($array1) && self::is_flat_array($array2)) {
			return array_merge($array1, $array2);
        } else {
            foreach ($array2 as $key => $value) {
                if (!isset($array1[$key]) || (!is_array($value) && !is_array($array1[$key]))) {
                    // If the key doesn't exist in array1 or either value is not an array, simply use the value from array2
                    $array1[$key] = $value;
                } elseif (is_array($value) && is_array($array1[$key])) {
                    // Both values are arrays, merge them based on the merge strategy
                    $array1[$key] = self::merge_array_values($array1[$key], $value, $deepMerge, $concatArrays);
                } else {
                    // If types don't match (one is array, the other is not), use the value from array2
                    $array1[$key] = $value;
                }
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
    public static function merge_array_values(array $value1, array $value2, bool $deepMerge, bool $concatArrays): array
    {
        $bothIndexed = self::is_indexed_array($value1) && self::is_indexed_array($value2);

        if (!$deepMerge) {
            return self::shallow_merge($value1, $value2, $bothIndexed, $concatArrays);
        }

        if ($bothIndexed) {
            return $concatArrays ? array_merge($value1, $value2) : $value2;
        }

        return self::merge_arrays($value1, $value2, true, $concatArrays);
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
    public static function shallow_merge(array $value1, array $value2, bool $bothIndexed, bool $concatArrays): array
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
    public static function is_indexed_array(array $array): bool
    {
        if (empty($array)) {
            return true; // Consider empty arrays as indexed
        }
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Determines if a given array is a flat array.
     *
     * An array is considered flat if none of its elements are arrays.
     *
     * @param array<mixed> $array The array to be checked.
     * @return bool Returns true if the array is flat, false otherwise.
     */
    public static function is_flat_array(array $array): bool {
        foreach ($array as $value) {
            if (is_array($value)) {
                return false;
            }
        }
        return true;
    }

}
