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
 *   Parameters: ActionScheduler_Action $action, int $action_id
 *
 * ### Group-Level Hooks
 * - `{sync_name}/finish` - Fires once when all actions in the group are complete
 *   Parameters: string $group_name
 *
 * ### Internal Hooks
 * - `{sync_name}/finish_check` - A scheduled action that re-evaluates completion
 *   and fires `{sync_name}/finish`. Completing work actions schedule it instead
 *   of firing the finish hook inline, which keeps the finish hook reliable when
 *   actions complete concurrently. Not intended for external use.
 *
 * ### Error Hooks
 * - `{sync_name}/fail` - Fires when an action fails with an exception
 *   Parameters: ActionScheduler_Action $action, Exception $e, int $action_id
 * - `{sync_name}/timeout` - Fires when an action times out
 *   Parameters: ActionScheduler_Action $action, int $action_id
 * - `{sync_name}/cancel` - Fires when an action is cancelled
 *   Parameters: ActionScheduler_Action $action, int $action_id
 * - `{sync_name}/delete` - Fires when an action is deleted
 *   Parameters: Chunk $chunk
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
	 * Defaults to 0 so reads before an action is in scope return falsy instead of
	 * throwing the "typed property must not be accessed before initialization"
	 * error (guards like `if ( ! $this->action_id )` rely on this).
	 *
	 * @var int
	 */
	protected int $action_id = 0;

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

		// Deferred finish job. Completing actions schedule this instead of firing
		// the finish hook inline, so the completion check runs after concurrent
		// actions have settled.
		add_action( $this->get_finish_check_hook(), array( $this, 'run_finish_check' ) );

		// If Sync finish execute after sync complete
		add_action( $this->get_sync_name() . '/finish', array( $this, 'on_finish' ) );

		add_action( 'action_scheduler_begin_execute', array( $this, 'handle_start' ), 10, 1 );
		add_action( 'action_scheduler_completed_action', array( $this, 'handle_complete' ), 10, 1 );
		add_action( 'action_scheduler_failed_action', array( $this, 'handle_timeout' ), 10 );
		add_action( 'action_scheduler_canceled_action', array( $this, 'handle_cancel' ), 10 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'handle_exception' ), 10, 2 );
		add_action( 'action_scheduler_deleted_action', array( $this, 'handle_delete' ) );

		// If Sync failed execute on_fail
		add_action( $this->get_sync_name() . '/fail', array( $this, 'on_fail' ) );
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
	 * Returns the sync group name. If none set it generates a unique one from the sync name.
	 *
	 * A UUID suffix (not a timestamp) is used so two runs of the same sync never
	 * share a group. Run-scoped state — chunk rows, sync data, and the
	 * `finish_fired_at` finish guard — is keyed by this group, so a collision
	 * would let one run's chunks and finish marker bleed into another.
	 *
	 * @return string
	 */
	public function get_sync_group_name(): string {
		if ( empty( $this->sync_group_name ) ) {
			$this->sync_group_name = sprintf(
				'%s_%s',
				$this->get_sync_name(),
				str_replace( '-', '', wp_generate_uuid4() )
			);
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
	 * 4. Schedules a deferred finish-check action that decides whether the run is done
	 *
	 * The finish hook itself is not fired here. Deferring it to its own action
	 * avoids a race where two chunk actions complete at the same time in separate
	 * processes and each reads the other as still running, so neither would fire
	 * the finish hook. See run_finish_check().
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

		// The finish-check action only re-evaluates completion. It carries no
		// chunk payload and must not schedule another finish-check for itself.
		if ( $action->get_hook() === $this->get_finish_check_hook() ) {
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

		// Defer the finish decision to a dedicated action so it is evaluated once
		// concurrent completions have settled. Only grouped work actions (chunks
		// and grouped fetches) drive this. Ungrouped spawner actions — the import
		// root, or a Sequential_Sync root that only enqueues child jobs — have no
		// group of their own to finish and must not schedule a finish-check.
		if ( ! empty( $action->get_group() ) ) {
			$this->schedule_finish_check();
		}
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

		// The finish-check action only re-evaluates completion. Setting the group
		// above is enough for run_finish_check(); it must not emit the /start hook.
		if ( $action->get_hook() === $this->get_finish_check_hook() ) {
			return;
		}

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
	 * Matches the sync's own hooks exactly — the root hook `{sync_name}` and its
	 * sub-hooks `{sync_name}/...` (process_chunk, finish_check). A plain substring
	 * test would wrongly claim actions of a different sync whose name merely
	 * contains this one (e.g. "orders" matching "orders_archive/process_chunk").
	 *
	 * @param int $action_id ID of the action to check.
	 * @return false|ActionScheduler_Action
	 */
	private function action_belongs_to_sync( int $action_id ): false|ActionScheduler_Action {
		$action    = ActionScheduler_Store::instance()->fetch_action( (string) $action_id );
		$hook      = $action->get_hook();
		$sync_name = $this->get_sync_name();

		if ( $hook !== $sync_name && ! str_starts_with( $hook, $sync_name . '/' ) ) {
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
	 * Handles the deletion of an action.
	 *
	 * Marks the chunk associated with the action as deleted, records the end time,
	 * and triggers a custom action for deletion. This is called, when the action is already deleted.
	 * Looking action details up is not possible. That is why the chunk is passed instead.
	 *
	 * @param int $action_id The ID of the action that was deleted.
	 * @return void
	 * @throws Exception DB Error.
	 */
	public function handle_delete( int $action_id ): void {

		// Since the action is already deleted, we can only check if it belongs to sync via the chunk DB.
		// This will not work for the dispatcher actions, but those do not have chunks anyway.
		$chunk = Chunk_DB::db()->get_chunk_by_action_id( $action_id );
		if ( ! $chunk || $chunk->get_status() === ProcessStatus::DELETED ) {
			return;
		}

		$group_name = $chunk->get_group();
		if ( empty( $group_name ) || ! str_starts_with( $group_name, $this->get_sync_name() . '_' ) ) {
			return;
		}

		$chunk->set_status( ProcessStatus::DELETED );
		$chunk->set_end();
		$chunk->save();

		do_action( $this->get_sync_name() . '/delete', $chunk );
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
	 * Determines whether the current completion event should trigger the final finish hook.
	 *
	 * The base Sync implementation considers a sync finished once no pending or running
	 * actions remain in the current group. Import variants can override this to add
	 * additional completion prerequisites.
	 *
	 * The action passed here is the finish-check action (see run_finish_check()),
	 * which is itself a running action in the group. It and any sibling
	 * finish-check actions are ignored so the run can be recognised as complete.
	 *
	 * @param ActionScheduler_Action $action The finish-check action being processed.
	 * @return bool
	 */
	protected function should_trigger_finish( ActionScheduler_Action $action ): bool {
		$group_name = $action->get_group();

		if ( empty( $group_name ) ) {
			return false;
		}

		$this->sync_group_name = $group_name;

		return ! $this->has_unfinished_actions();
	}

	/**
	 * Whether the current group still has pending or running work actions.
	 *
	 * Finish-check actions are infrastructure, not work, so they do not count as
	 * unfinished. This lets the finish-check that is currently running (and any
	 * duplicate that was scheduled by a concurrent completion) recognise the run
	 * as complete. The answer is derived from two count queries — total pending or
	 * running minus the finish-checks among them — so it is exact regardless of
	 * how many actions remain (no per-action hydration, no result-page cap).
	 *
	 * @return bool
	 */
	private function has_unfinished_actions(): bool {
		if ( ! ActionScheduler::is_initialized( __FUNCTION__ ) ) {
			return false;
		}

		$store    = ActionScheduler::store();
		$statuses = array(
			ActionScheduler_Store::STATUS_PENDING,
			ActionScheduler_Store::STATUS_RUNNING,
		);

		$pending_total = (int) $store->query_actions(
			array(
				'group'   => $this->get_sync_group_name(),
				'claimed' => null,
				'status'  => $statuses,
			),
			'count'
		);

		if ( 0 === $pending_total ) {
			return false;
		}

		$pending_finish_checks = (int) $store->query_actions(
			array(
				'group'   => $this->get_sync_group_name(),
				'hook'    => $this->get_finish_check_hook(),
				'claimed' => null,
				'status'  => $statuses,
			),
			'count'
		);

		return $pending_total > $pending_finish_checks;
	}

	/**
	 * Return the hook used for the deferred finish-check action.
	 *
	 * @return string
	 */
	protected function get_finish_check_hook(): string {
		return $this->get_sync_name() . '/finish_check';
	}

	/**
	 * Schedule the deferred finish-check action for the current run.
	 *
	 * Called from every work-action completion. At most one finish-check is kept
	 * pending per run, so this stays cheap no matter how many chunks complete.
	 * The check itself is authoritative and runs after the completions settle, so
	 * the finish hook is reached even when the inline state was ambiguous.
	 *
	 * @return void
	 */
	protected function schedule_finish_check(): void {
		$group = $this->get_sync_group_name();
		$hook  = $this->get_finish_check_hook();

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		if ( ! empty( $pending ) ) {
			return;
		}

		as_enqueue_async_action( $hook, array(), $group );
	}

	/**
	 * Callback for the deferred finish-check action.
	 *
	 * Re-evaluates whether the run is complete and, if so, fires the finish hook
	 * exactly once. Running this as its own action means the completion check
	 * happens after the concurrent work actions have settled, which is what makes
	 * the finish hook reliable under parallel execution. mark_finish_ready() still
	 * guards against a duplicate finish-check firing twice.
	 *
	 * @return void
	 * @throws Exception When sync data access fails.
	 */
	public function run_finish_check(): void {
		if ( ! $this->action_id ) {
			return;
		}

		// A missing action yields a null-object whose group is empty, which
		// should_trigger_finish() treats as "not ready".
		$action = ActionScheduler_Store::instance()->fetch_action( (string) $this->action_id );

		if ( ! $this->should_trigger_finish( $action ) ) {
			return;
		}

		if ( ! $this->mark_finish_ready() ) {
			return;
		}

		do_action( $this->get_sync_name() . '/finish', $this->get_sync_group_name() );
	}

	/**
	 * Marks the current run as finished and reports whether this caller won the race.
	 *
	 * Several chunk actions can complete at the same time in separate processes,
	 * so more than one of them may observe an empty group at once. This stores a
	 * `finish_fired_at` marker atomically, scoped to the sync group name, and
	 * returns true only for the single caller that stored it. The group scope
	 * keeps the marker isolated per run, including sequential-sync child jobs
	 * that share a sync-data name but run under their own group.
	 *
	 * @return bool True when this caller should fire the finish hook.
	 * @throws Exception When the marker cannot be written.
	 */
	protected function mark_finish_ready(): bool {
		return $this->add_once( 'finish_fired_at', time(), $this->get_sync_group_name() );
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
