<?php
/**
 * Trait Finish_Tracking
 *
 * @package juvo/as-processor
 */

namespace juvo\AS_Processor;

use ActionScheduler;
use ActionScheduler_Store;
use Exception;

/**
 * Trait Finish_Tracking
 *
 * Decides when a sync run is complete and fires `{sync_name}/finish` exactly
 * once, even when chunk actions complete concurrently in separate processes.
 *
 * Rather than deciding inline at the moment a chunk completes — which can race
 * so that two final chunks each read the other as still running and neither
 * fires finish — every completing work action schedules a deferred
 * `{sync_name}/finish_check` action. That action re-evaluates completion after
 * the concurrent work has settled and fires the finish hook once, guarded by an
 * atomic, group-scoped marker.
 *
 * The host class must provide: get_sync_name(), get_sync_group_name(),
 * should_trigger_finish(), add_once() (from Sync_Data), and the $action_id
 * property. set_hooks() must register run_finish_check() on the finish-check
 * hook, and the Action Scheduler lifecycle handlers must skip the finish-check
 * action (it is infrastructure, not sync work).
 */
trait Finish_Tracking {

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
	 * Several finish-checks can run at the same time in separate processes, so
	 * more than one may observe an empty group at once. This stores a
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
	protected function has_unfinished_actions(): bool {
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
}
