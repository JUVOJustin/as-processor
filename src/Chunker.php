<?php
/**
 * Trait Chunker
 *
 * Provides functionality for processing data in chunks using WordPress Action Scheduler.
 * Handles scheduling, processing, and cleanup of chunked data operations.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor;

use Exception;
use Generator;
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\Entities\ProcessStatus;
use juvo\AS_Processor\Entities\Chunk;

/**
 * The Chunker.
 */
trait Chunker {

	/**
	 * Schedules an async action to process a chunk of data. Passed items are serialized and added to a chunk.
	 *
	 * @param mixed $chunk_data The data to be processed in chunks.
	 * @return void
	 * @throws Exception When chunk data insertion fails.
	 */
	protected function schedule_chunk( mixed $chunk_data ): void {
		
		// update chunk counter
		if ( property_exists( $this, 'chunk_counter' ) ) {
			$this->chunks_count = DB\Chunk_DB::db()->get_total_actions( $this->get_sync_group_name() );
		}

		// check if we have a chunk limit
		if (
		    property_exists( $this, 'chunk_limit' )
		    && property_exists( $this, 'chunk_counter' )
		    && 0 !== $this->chunk_limit
		    && $this->chunk_counter >= $this->chunk_limit
		) {
		    return;
		}

		// convert to array if it's an iterator
		if ( ! is_array( $chunk_data ) ) {
			$chunk_data = iterator_to_array( $chunk_data );
		}

		// create the new chunk
		$chunk = new Chunk();
		$chunk->set_status( ProcessStatus::SCHEDULED );
		$chunk->set_data( $chunk_data );
		$chunk->save();

		as_enqueue_async_action(
			$this->get_sync_name() . '/process_chunk',
			array(
				'chunk_id' => $chunk->get_chunk_id(),
			), // Wrap in array to pass as single argument. Needed because of abstract child method enforcement
			$this->get_sync_group_name()
		);
	}

	/**
	 * Callback function for the single chunk jobs.
	 * This jobs reads the serialized chunk data from the database and processes it.
	 *
	 * @param int $chunk_id The ID of the chunk to process.
	 * @throws Exception When chunk data is empty or invalid.
	 * @return void
	 */
	protected function import_chunk( int $chunk_id ): void {
		// set the new status of the chunk
		$chunk = new Chunk( $chunk_id );
		$chunk->set_status( ProcessStatus::RUNNING );
		$chunk->save();

		// fetch the data
		$data = $chunk->get_data();

		// Convert array to Generator
		$generator = ( function () use ( $data ) {
			foreach ( $data as $key => $value ) {
				yield $key => $value;
			}
		} )();

		$this->process_chunk_data( $generator );
	}

	/**
	 * Handles the actual data processing. Should be implemented in the class lowest in hierarchy.
	 *
	 * @param Generator $chunk_data The generator containing chunk data to process.
	 * @return void
	 */
	abstract protected function process_chunk_data( Generator $chunk_data ): void;

	/**
	 * Cleans the chunk data table from data with following properties:
	 * - older than 2 days (start)
	 * - status must be finished
	 *
	 * @return void
	 */
	public function cleanup_chunk_data(): void {
		Chunk_DB::db()->cleanup();
	}
}
