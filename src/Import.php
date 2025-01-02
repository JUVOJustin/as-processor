<?php
/**
 * Abstract class for managing data imports with support for chunking large datasets.
 *
 * This class provides methods to divide large datasets into smaller manageable chunks
 * and process each chunk independently. Subclasses are required to implement specific logic
 * for splitting the data into chunks.
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

use Exception;

/**
 * Abstract class for managing data imports with support for chunking large datasets.
 *
 * This class provides methods to divide large data sets into smaller manageable chunks
 * and process each chunk independently. Subclasses must define specific implementations
 * for handling the chunking process.
 */
abstract class Import extends Sync {

	/**
	 * Counter to keep track of the number of processed chunks.
	 *
	 * @var int
	 */
	protected int $chunk_counter = 0;

	/**
	 * Optional: Allows setting a maximum of chunks to be processed.
	 *
	 * @var int
	 */
	protected int $chunk_limit = 0;

	/**
	 * Adds the hooks for the chunking
	 *
	 * @return  void
	 */
	public function set_hooks(): void {
		parent::set_hooks();
		add_action( $this->get_sync_name(), array( $this, 'split_data_into_chunks' ) );
		add_action( $this->get_sync_name() . '/process_chunk', array( $this, 'process_chunk' ) );
	}

	/**
	 * Split the data, wherever it comes from into chunks.
	 * This function has to be implemented within each "Import".
	 * The basic workflow is:
	 *  1. Get the source data
	 *  2. Split the data into the smaller subsets called chunks.
	 *  3. Schedule chunks to of data using the Chunker.php trait
	 *
	 * @return void
	 */
	abstract public function split_data_into_chunks(): void;

	/**
	 * Processes a specific chunk of data identified by the chunk ID.
	 * This function handles the processing of the selected chunk by importing its data.
	 *
	 * @param int $chunk_id The unique identifier of the chunk to be processed.
	 * @return void
	 * @throws Exception If chunk data invalid or empty.
	 */
	public function process_chunk( int $chunk_id ): void {
		$this->import_chunk( $chunk_id );
	}
}
