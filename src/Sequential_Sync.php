<?php
/**
 * Allows adding syncs in a sequence to ensure execution in the proper order
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

use Exception;
use SplQueue;

/**
 * Class Sequential_Sync
 *
 * Base class responsible for managing the execution of sequential sync tasks.
 * It organizes tasks in a queue and manages their execution in a predefined sequence.
 * The class ensures that each task in the sequence is properly handled and allows
 * for the registration of hooks for sync-specific operations.
 */
abstract class Sequential_Sync implements Syncable {


	use Sync_Data;

	/**
	 * Stores all the sync tasks in a queue
	 *
	 * @var SplQueue
	 */
	private SplQueue $queue;

	/**
	 * Stores the sequence of sync jobs to be executed.
	 *
	 * This array holds instances of the `Sync` class, each representing a specific synchronization job
	 * that is part of a sequential synchronization process. The `$jobs` array is initialized with the
	 * sync tasks returned by the `get_jobs()` method, which needs to be implemented by classes extending
	 * from `Sequential_Sync`.
	 *
	 * ### Purpose:
	 * - The `$jobs` variable ensures that all synchronization jobs are loaded and managed as part of the
	 *   sequence. These jobs are not instantiated on every page load but only when the synchronization
	 *   process is initiated, keeping resource usage efficient.
	 *
	 * ### Usage:
	 * - The `queue_init()` method initializes this property with the return value of `get_jobs()`
	 *   and registers hooks for each job’s specific sync behavior.
	 * - It provides the functionality to enqueue and process these jobs in the correct order during
	 *   the synchronization cycle.
	 * - The jobs can communicate and share synchronization data through the `sync_data_name` property
	 *   and hooks registered for actions like job completion or intermediate steps.
	 *
	 * ### When to Use:
	 * - Use `$jobs` when scheduling or managing multiple sync tasks as part of a sequential process.
	 * - Any job that needs to be executed as part of the sequence must be registered in the array via
	 *   the `get_jobs()` method.
	 * - It is especially useful for applications where sync tasks must follow a specific logical order
	 *   for successful execution.
	 *
	 * ### Example Use Case:
	 * - Suppose you have a series of data synchronization tasks where data from one sync operation is
	 *   necessary for the next. You would define these tasks as instances of `Sync` and return them in
	 *   `get_jobs()`. They are then stored in `$jobs` and executed sequentially, ensuring proper order
	 *   and data integrity.
	 *
	 * @var Sync[] Array of `Sync` instances representing the jobs to be executed sequentially.
	 */
	private array $jobs;

	/**
	 * Contains the hook name if the current sync being executed
	 *
	 * @var ?Sync
	 */
	private ?Sync $current_sync = null;

	/**
	 * Constructor method to initialize synchronization data name
	 * and register hooks for processing the synchronization sequence.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->sync_data_name = $this->get_sync_name();

		// Run always on initialisation
		add_action(
			'init',
			function () {
				$this->queue_init();
			}
		);

		// Run the callback function once action is triggered to start the process
		add_action( $this->get_sync_name(), array( $this, 'callback' ) );

		// Delete sync data after sync is complete
		add_action( $this->get_sync_name() . '/complete', array( $this, 'delete_sync_data' ), 999 );
	}

	/**
	 * Syncs/Jobs that are in a sequence are not instantiated on every page load, which is why their hook callbacks are not registered.
	 * This function goes through all jobs and registers their hooks
	 *
	 * @return void
	 */
	protected function queue_init(): void {
		// Since get-Jobs returns fresh instances of Sync, the hooks of the respective sync are always added
		$this->jobs = $this->get_jobs();

		foreach ( $this->jobs as $job ) {

			// Overwrite sync data name to share data between jobs
			$job->set_sync_data_name( $this->get_sync_name() );

			// Registering the "next" function to the "complete" hook is essential to run the next job in the sequence
			add_action( $job->get_sync_name() . '/complete', array( $this, 'next' ) );
		}
	}

	/**
	 * Retrieves and restores data for the sync process.
	 *
	 * @return void
	 * @throws Exception DB Error.
	 */
	private function retrieve_data(): void {

		$queue        = $this->get_sync_data( 'queue' );
		$current_sync = $this->get_sync_data( 'current_sync' );

		if ( empty( $queue ) ) {
			$this->queue = new SplQueue();
		} else {

			// Restore data from run
			$this->queue        = $queue;
			$this->current_sync = $current_sync ?? null;
		}
	}

	/**
	 * Runs the next job in the queue
	 *
	 * @throws Exception DB Error.
	 */
	public function next(): void {

		// Prepare Data
		$this->retrieve_data();

		// If queue is empty we either never started or we are done
		if ( $this->queue->isEmpty() ) {

			// If current sync is filled it means this is not the first call
			if ( $this->current_sync ) {

				// Reset data
				$this->current_sync = null;
				$this->update_sync_data( 'queue', $this->queue );
				$this->update_sync_data( 'current_sync', null );

				// Allow working on data after sync is complete
				do_action( $this->get_sync_name() . '/complete' );
			}
			return;
		}

		$sync               = $this->queue->dequeue();
		$this->current_sync = $sync;

		// Save current queue back to db
		$this->update_sync_data( 'queue', $this->queue );
		$this->update_sync_data( 'current_sync', $this->current_sync );

		// Execute Sync
		do_action( $this->current_sync->get_sync_name() );
	}

	/**
	 * Handles the callback for the job enqueue process.
	 * Retrieves the sync data and enqueues each job.
	 * Starts the job processing.
	 *
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function callback(): void {

		// Prepare Data
		$this->retrieve_data();

		// Check if is already running
		if ( $this->current_sync ) {
			throw new Exception( 'Sync already started' );
		}

		$jobs = $this->jobs;
		if ( empty( $jobs ) ) {
			return;
		}

		foreach ( $jobs as $job ) {
			$this->enqueue( $job );
		}

		$this->next();
	}

	/**
	 * Adds a task to the queue
	 *
	 * @param Sync $task Task to be added, could be a callback or any data type representing a task.
	 * @return bool
	 * @throws Exception DB Error.
	 */
	private function enqueue( Sync $task ): bool {

		// Only enqueue new jobs if the queue did not start yet
		if ( $this->current_sync ) {
			return false;
		}

		$this->queue->enqueue( $task );

		$this->update_sync_data( 'queue', $this->queue );

		return true;
	}

	/**
	 * Retrieves an array of jobs that need to be synced.
	 *
	 * The implementing class should provide the logic for retrieving the jobs.
	 *
	 * @return Sync[] An array of jobs that need to be synced.
	 */
	abstract protected function get_jobs(): array;
}
