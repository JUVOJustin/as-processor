<?php

namespace juvo\AS_Processor;

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
}