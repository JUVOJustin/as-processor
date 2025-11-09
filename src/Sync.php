<?php
/**
 * Provides an abstract base class for synchronization processes that interact with Action Scheduler.
 * This class establishes hooks and manages the synchronization lifecycle using Action Scheduler.
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

use ActionScheduler;
use ActionScheduler_Action;
use ActionScheduler_Store;
use Exception;
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\DB\Data_DB;
use juvo\AS_Processor\Entities\Chunk;
use juvo\AS_Processor\Entities\ProcessStatus;

/**
 * Abstract base class for managing synchronization processes utilizing Action Scheduler.
 *
 * This class initializes hooks, processes actions in chunks, handles errors, and manages
 * group naming and lifecycle for synchronization tasks. It provides structure and methods
 * for scheduling, tracking, and completing chunks within a synchronization process.
 *
 * ## Lifecycle Hooks
 *
 * The Sync class provides several lifecycle hooks that fire at different stages:
 *
 * ### Per-Action Hooks
 * - `{sync_name}/complete` - Fires for each completed action in the sync group
 *   Parameters: int $action_id
 *
 * ### Group-Level Hooks
 * - `{sync_name}/finish` - Fires once when all actions in the group are complete
 *   Parameters: string $group_name
 *
 * ### Error Hooks
 * - `{sync_name}/fail` - Fires when an action fails with an exception
 *   Parameters: ActionScheduler_Action $action, Exception $e, int $action_id
 * - `{sync_name}/timeout` - Fires when an action times out
 *   Parameters: ActionScheduler_Action $action, int $action_id
 * - `{sync_name}/cancel` - Fires when an action is cancelled
 *   Parameters: ActionScheduler_Action $action, int $action_id
 *
 * ## Overridable Methods
 *
 * Child classes can override these methods to customize behavior:
 * - `on_finish()` - Called when all actions complete
 * - `on_fail()` - Called when an action fails
 *
 * @package juvo/as-processor
 */
abstract class Sync implements Syncable {

	use Sync_Data;
	use Chunker;

	/**
	 * Name of the group the sync belongs to
	 *
	 * @var string
	 */
	private string $sync_group_name;

	/**
	 * ID of the action scheduler action in scope.
	 *
	 * @var int
	 */
	protected int $action_id;

	/**
	 * Initializes the class instance.
	 *
	 * Calls the necessary hooks and sets up the environment required for the instance.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->set_hooks();
	}

	/**
	 * Used to add callbacks to hooks
	 *
	 * @return void
	 */
	public function set_hooks(): void {
		add_action( $this->get_sync_name() . '/process_chunk', array( $this, 'process_chunk' ) );

		// If Sync finish execute after sync complete
		add_action( $this->get_sync_name() . '/finish', array( $this, 'on_finish' ) );

		add_action( 'action_scheduler_begin_execute', array( $this, 'handle_start' ), 10, 1 );
		add_action( 'action_scheduler_completed_action', array( $this, 'handle_complete' ), 10, 1 );
		add_action( 'action_scheduler_failed_action', array( $this, 'handle_timeout' ), 10 );
		add_action( 'action_scheduler_canceled_action', array( $this, 'handle_cancel' ), 10 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'handle_exception' ), 10, 2 );

		// If Sync failed execute on_fail
		add_action( $this->get_sync_name() . '/fail', array( $this, 'on_fail' ) );

		// Hook Sync DB Cleanup
		add_action(
			'init',
			function () {
				if ( as_has_scheduled_action( 'asp/cleanup' ) ) {
					return;
				}

				// schedule the cleanup midnight every day
				as_schedule_cron_action(
					time(),
					'0 0 * * *',
					'asp/cleanup'
				);
			}
		);
		add_action(
			'asp/cleanup',
			function () {
				Chunk_DB::db()->cleanup();
				Data_DB::db()->delete_expired_data();
			}
		);
	}

	/**
	 * Returns the name of the sync. The name must always be deterministic.
	 *
	 * @return string
	 */
	abstract public function get_sync_name(): string;

	/**
	 * Callback for the Chunk jobs. The child implementation either dispatches to an import or an export
	 *
	 * @param int $chunk_id Database ID of the chunk that should be processed.
	 * @return void
	 */
	abstract protected function process_chunk( int $chunk_id ): void;

	/**
	 * Returns the sync group name. If none set it will generate one from the sync name and the current time
	 *
	 * @return string
	 */
	public function get_sync_group_name(): string {
		if ( empty( $this->sync_group_name ) ) {
			$this->sync_group_name = $this->get_sync_name() . '_' . time();
		}
		return $this->sync_group_name;
	}

	/**
	 * Query actions belonging to this specific group.
	 *
	 * @param array $status array of Stati to query. By default, queries all.
	 * @param int   $per_page number of action ids to receive.
	 * @return int[]|false
	 */
	protected function get_actions( array $status = array(), int $per_page = 5 ): array|false {

		if ( ! ActionScheduler::is_initialized( __FUNCTION__ ) ) {
			return false;
		}

		$store      = ActionScheduler::store();
		$action_ids = $store->query_actions(
			array(
				'group'    => $this->get_sync_group_name(),
				'claimed'  => null,
				'status'   => $status,
				'per_page' => $per_page,
			)
		);

		return array_map(
			function ( $action_id ) {
				return intval( $action_id );
			},
			$action_ids
		);
	}

	/**
	 * Handles per-action completion events for actions in the sync group.
	 *
	 * This method is called by Action Scheduler's native `action_scheduler_completed_action` hook
	 * whenever any action completes. It:
	 * 1. Verifies the action belongs to this sync
	 * 2. Updates the chunk status to FINISHED
	 * 3. Fires the per-action `{sync_name}/complete` hook (passes ActionScheduler_Action object)
	 * 4. Checks if all actions in the group are done
	 * 5. If all complete, fires the `{sync_name}/finish` hook once
	 *
	 * @param int $action_id ID of the completed action.
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function handle_complete( int $action_id ): void {

		$action = $this->action_belongs_to_sync( $action_id );
		if ( ! $action ) {
			return;
		}

		// avoid recursion by not hooking a complete action while
		// in complete context
		if ( $action->get_hook() === $this->get_sync_name() . '/complete' ) {
			return;
		}

		// set the end time of the chunk
		$action_arguments = $action->get_args();
		if ( ! empty( $action_arguments['chunk_id'] ) ) {
			$chunk = new Chunk( $action_arguments['chunk_id'] );
			$chunk->set_status( ProcessStatus::FINISHED );
			$chunk->set_end();
			$chunk->save();
		}

		// Fire per-action completion hook
		do_action( $this->get_sync_name() . '/complete', $action, $action_id );
	}

	/**
	 * Runs when an action is started.
	 *
	 * Callback for "action_scheduler_before_execute" hook. It gets the current action and (re)sets the group name.
	 * This is needed to have a consistent group name through all executions of a group.
	 *
	 * Fires a '{sync_name}/start' action hook with the ActionScheduler_Action object and action ID as parameters,
	 * allowing external code to hook into the start of any sync-related action (both dispatcher and chunk actions).
	 *
	 * @param int $action_id ID of the action to track.
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function handle_start( int $action_id ): void {
		$action = $this->action_belongs_to_sync( $action_id );
		if ( ! $action ) {
			return;
		}

		$this->sync_group_name = $action->get_group();

		// set the start time of the chunk
		$action_arguments = $action->get_args();
		if ( ! empty( $action_arguments['chunk_id'] ) ) {
			$chunk = new Chunk( $action_arguments['chunk_id'] );
			$chunk->set_status( ProcessStatus::STARTED );
			$chunk->set_start();
			$chunk->save();
		}

		do_action( $this->get_sync_name() . '/start', $action, $action_id );
	}

	/**
	 * Checks if the passed action belongs to the sync. If so returns the action object else false.
	 *
	 * @param int $action_id ID of the action to check.
	 * @return false|ActionScheduler_Action
	 */
	private function action_belongs_to_sync( int $action_id ): false|ActionScheduler_Action {
		$action = ActionScheduler_Store::instance()->fetch_action( (string) $action_id );

		// Action must contain the sync name as hook. Else it does not belong to sync
		if ( ! str_contains( $action->get_hook(), $this->get_sync_name() ) ) {
			return false;
		}

		$this->action_id = $action_id;

		return $action;
	}

	/**
	 * Wraps the execution of the on_fail method, if it exists, for error handling.
	 * Checks if the errored action belongs to the sync. If so passes the action instead of the action_id to be more flexible
	 *
	 * @param int       $action_id The ID of the action.
	 * @param Exception $e The exception that was thrown.
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function handle_exception( int $action_id, Exception $e ): void {

		// Check if action belongs to sync
		$action = $this->action_belongs_to_sync( $action_id );
		if ( ! $action ) {
			return;
		}

		// set the end time of the chunk
		$action_arguments = $action->get_args();
		if ( ! empty( $action_arguments['chunk_id'] ) ) {
			$chunk = new Chunk( $action_arguments['chunk_id'] );
			$chunk->set_status( ProcessStatus::FAILED );
			$chunk->set_end();
			$chunk->save();
		}

		do_action( $this->get_sync_name() . '/fail', $action, $e, $action_id );
	}

	/**
	 * Handles timeout events for a specified action.
	 *
	 * Updates the status and end time of the associated chunk and triggers a timeout action hook.
	 *
	 * @param int $action_id The ID of the action that timed out.
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function handle_timeout( int $action_id ): void {

		// Check if action belongs to sync
		$action = $this->action_belongs_to_sync( $action_id );
		if ( ! $action ) {
			return;
		}

		// set the end time of the chunk
		$action_arguments = $action->get_args();
		if ( ! empty( $action_arguments['chunk_id'] ) ) {
			$chunk = new Chunk( $action_arguments['chunk_id'] );
			$chunk->set_status( ProcessStatus::TIMED_OUT );
			$chunk->set_end();
			$chunk->save();
		}

		do_action( $this->get_sync_name() . '/timeout', $action, $action_id );
	}

	/**
	 * Handles the cancellation of an action.
	 *
	 * Marks the chunk associated with the action as canceled, records the end time,
	 * and triggers a custom action for cancellation.
	 *
	 * @param int $action_id The ID of the action to be canceled.
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function handle_cancel( int $action_id ): void {

		// Check if action belongs to sync
		$action = $this->action_belongs_to_sync( $action_id );
		if ( ! $action ) {
			return;
		}

		// set the end time of the chunk
		$action_arguments = $action->get_args();
		if ( ! empty( $action_arguments['chunk_id'] ) ) {
			$chunk = new Chunk( $action_arguments['chunk_id'] );
			$chunk->set_status( ProcessStatus::CANCELLED );
			$chunk->set_end();
			$chunk->save();
		}

		do_action( $this->get_sync_name() . '/cancel', $action, $action_id );
	}

	/**
	 * Logs a message with the specified log level and triggers a WordPress error.
	 *
	 * Logs the message using ActionScheduler associated with the current action ID.
	 * Additionally, triggers a WordPress error with the provided details.
	 *
	 * @param string                                                                   $message The message to log.
	 * @param int-mask-of<E_USER_ERROR|E_USER_WARNING|E_USER_NOTICE|E_USER_DEPRECATED> $log_level The log level for the WordPress error. Defaults to E_USER_NOTICE.
	 * @param string|null                                                              $function_name The name of the function triggering the log. Defaults to the current function name.
	 * @return void
	 *
	 * @throws \Exception Thrown when log-level is E_USER_ERROR and WP_DEBUG is true.
	 */
	protected function log( string $message, int $log_level = E_USER_NOTICE, ?string $function_name = null ): void {

		if ( ! $this->action_id ) {
			return;
		}

		ActionScheduler::logger()->log(
			$this->action_id,
			$message
		);

		if ( empty( $function_name ) && WP_DEBUG ) {
			$backtrace     = debug_backtrace(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$function_name = $backtrace[1]['function'] ?? __FUNCTION__;
		}

		wp_trigger_error(
			$function_name,
			sprintf(
				'[action_id: %d] [group: %s] %s',
				$this->action_id,
				$this->sync_group_name ?? 'undefined',
				$message
			),
			$log_level
		);
	}

	/**
	 * Executes tasks after all actions in the synchronization group are finished.
	 *
	 * This method is triggered when all actions in the sync group have completed.
	 * It can perform cleanup tasks, post-sync operations, or finalize other processes tied to the sync group.
	 *
	 * Intention is to overwrite this method in child classes to ease implementation.
	 *
	 * @return void
	 */
	public function on_finish(): void {}

	/**
	 * Handles the behavior when an action fails.
	 *
	 * This method is triggered when a job or process encounters a failure.
	 * Can be extended to include specific failure handling or cleanup logic.
	 *
	 * Intention is to overwrite this method in child classes to ease implementation.
	 *
	 * @return void
	 * @throws Exception When the failure cannot be processed properly.
	 */
	public function on_fail(): void {}
}
