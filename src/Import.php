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
use juvo\AS_Processor\DB\Chunk_DB;

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

		add_action( $this->get_sync_name() . '/start', array( $this, 'track_scheduling_action' ), 10, 1 );
		add_action( $this->get_sync_name() . '/complete', array( $this, 'on_complete' ), 10, 1 );
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

	/**
	 * Track the start time of the action that schedules the chunks.
	 *
	 * @param \ActionScheduler_Action $action The action being started.
	 * @return void
	 * @throws Exception When sync data update fails.
	 */
	public function track_scheduling_action( \ActionScheduler_Action $action ): void {

		if ( empty( $action->get_group() ) ) {
			$this->get_run_sync_data_store()->update( 'spawning_action_started_at', time() );
		}
	}

	/**
	 * Tracks the end time of the action that schedules the chunks and triggers the finish action if applicable.
	 *
	 * @param \ActionScheduler_Action $action The action being completed.
	 * @return void
	 * @throws Exception When sync data update or retrieval fails.
	 */
	public function on_complete( \ActionScheduler_Action $action ): void {

		if ( empty( $action->get_group() ) ) {
			$this->get_run_sync_data_store()->update( 'spawning_action_ended_at', time() );
		}
	}

	/**
	 * Imports finish only after all tracked chunks are complete and the spawning action has both started and ended.
	 *
	 * @param \ActionScheduler_Action $action The action that just completed.
	 * @return bool
	 * @throws Exception When sync data retrieval fails.
	 */
	protected function should_trigger_finish( \ActionScheduler_Action $action ): bool {
		if ( ! parent::should_trigger_finish( $action ) ) {
			return false;
		}

		$completed = Chunk_DB::db()->are_all_chunks_completed( $this->get_sync_group_name() );

		return (
			$completed
			&& $this->get_run_sync_data_store()->get( 'spawning_action_started_at' )
			&& $this->get_run_sync_data_store()->get( 'spawning_action_ended_at' )
		);
	}
}
