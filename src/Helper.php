<?php

namespace juvo\AS_Processor;

use DateTimeImmutable;

class Helper
{
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
}