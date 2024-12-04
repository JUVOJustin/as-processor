<?php
/**
 * Handles JSON Imports.
 *
 * @package juvo\AS_Processor
 */
namespace juvo\AS_Processor\Imports;

use Exception;
use WP_Error;
use WP_Filesystem_Direct;
use juvo\AS_Processor\Import;

/**
 * An abstract class that extends the Import class, providing functionality for handling
 * JSON data from a source file, including splitting data into chunks and processing JSON decoding.
 */
abstract class JSON extends Import
{
    /**
     * The size of the chunks
     *
     * @var int
     */
    public int $chunk_size = 10;

    /**
     * The maximum depth allowed for parsing
     *
     * @var int
     */
    public int $depth = 512;

    /**
     * Bitmask consisting of JSON constants, which forces a JSON function to throw a JsonException if an error occurs
     *
     * @var int
     */
    public int $flags = JSON_THROW_ON_ERROR;

    /**
     * Indicates if the array is associative
     *
     * @var bool
     */
    public bool $associative = TRUE;

    /**
     * Retrieves the source path as a string.
     *
     * @return string The source path.
     */
    abstract protected function get_source_path(): string;

    /**
     * Splits the fetched data into smaller chunks and schedules each chunk for processing.
     *
     * @throws Exception If the source file cannot be located.
     */
    public function split_data_into_chunks(): void
    {
        $filepath = $this->get_source_path();

        if (! is_file($filepath)) {
            throw new Exception(
                sprintf(
                    '%s - %s',
                    $this->get_sync_name(),
                    __('Could not locate file.', 'asp')
                )
            );
        }

        $data = $this->fetch_data_from_source_file( $filepath );

        if (empty($data)) {
            return;
        }

        $chunks = array_chunk($data, $this->chunk_size);

        foreach ($chunks as $chunk) {
            $this->schedule_chunk($chunk);
        }
    }

    /**
     * Processes the fetching of data from a specified file path.
     *
     * @param string $filepath the path to the json file
     * @return array<mixed> The decoded JSON data or an empty array on error
     * @throws Exception When JSON decoding fails
     */
    public function fetch_data_from_source_file( string $filepath ): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $wp_filesystem = new WP_Filesystem_Direct(null);
        $data = $wp_filesystem->get_contents($filepath);

        if (false === $data) {
            throw new Exception(
                sprintf(
                    '%s - %s',
                    $this->get_sync_name(),
                    __('Could not read file contents.', 'asp')
                )
            );
        }

        try {
            $decoded_data = json_decode($data, $this->associative, $this->depth, $this->flags);
        } catch (Exception $e) {
            throw new Exception(
                sprintf(
                    '%s - %s',
                    $this->get_sync_name(),
                    $e->getMessage()
                )
            );
        }

        if ($decoded_data instanceof WP_Error || empty($decoded_data)) {
            return [];
        }

        return $decoded_data;
    }
}
