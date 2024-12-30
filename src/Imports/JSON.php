<?php
/**
 * Handles JSON Imports.
 *
 * @package juvo\AS_Processor
 */
namespace juvo\AS_Processor\Imports;

use Exception;
use JsonMachine\Items;
use juvo\AS_Processor\Helper;
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
    public int $chunkSize = 10;

	/**
	 * You can add a JSON Pointer to only get certain data
	 *
	 * @var string|null
	 * @link https://github.com/halaxa/json-machine?tab=readme-ov-file#json-pointer
	 */
	public ?string $pointer = "";

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
		$wp_filesystem = Helper::get_direct_filesystem();

        if (! $wp_filesystem->is_file($filepath)) {
            throw new Exception(
                sprintf(
                    '%s - %s',
                    $this->get_sync_name(),
                    __('Could not locate file.', 'asp')
                )
            );
        }

		$chunkData = [];
		$items = Items::fromFile($filepath, ['pointer' => $this->pointer]);
		foreach ($items as $item) {

			$chunkData[] = $item;

			// schedule chunk and empty chunk data
			if ( count($chunkData) >= $this->chunkSize ) {
				$this->schedule_chunk($chunkData);
				$chunkData = [];
			}
		}

		// Add remaining elements into a last chunk
		if ( ! empty( $chunkData ) ) {
			$this->schedule_chunk($chunkData);
		}

		// Delete
		$unlink_result = $wp_filesystem->delete($filepath);
		if ( $unlink_result === false ) {
			throw new Exception("File '$filepath' could not be deleted!");
		}
    }
}
