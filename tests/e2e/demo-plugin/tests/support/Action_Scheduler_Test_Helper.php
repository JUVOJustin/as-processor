<?php
/**
 * Focused Action Scheduler helpers for application tests.
 *
 * Uses the real ActionScheduler_QueueRunner so tests exercise the same
 * dispatch path as production (argument serialization, hook resolution,
 * status transitions).
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Support;

use ActionScheduler_QueueRunner;
use ActionScheduler_Store;

final class Action_Scheduler_Test_Helper {

	/**
	 * Execute a single queued action through the real queue runner.
	 *
	 * @param int $action_id Action Scheduler action ID.
	 */
	public static function run_action( int $action_id ): void {
		ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'PHPUnit' );
	}

	/**
	 * Return pending action IDs for an exact hook (and optional group).
	 *
	 * @param string      $hook  Action hook.
	 * @param string|null $group Optional action group.
	 * @return int[]
	 */
	public static function get_pending_action_ids( string $hook, ?string $group = null ): array {
		$query = array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		);

		if ( null !== $group ) {
			$query['group'] = $group;
		}

		return array_map( 'intval', as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * Run every pending action matching the given hook and optional group.
	 *
	 * @param string      $hook  Action hook.
	 * @param string|null $group Optional action group.
	 * @return int Number of actions processed.
	 */
	public static function run_all_pending( string $hook, ?string $group = null ): int {
		$action_ids = self::get_pending_action_ids( $hook, $group );

		foreach ( $action_ids as $action_id ) {
			self::run_action( $action_id );
		}

		return count( $action_ids );
	}

	/**
	 * Process a set of hooks repeatedly until no pending actions remain.
	 *
	 * Action Scheduler jobs can spawn more jobs (dispatcher → chunks, API
	 * fetch → next fetch). Looping until idle keeps the assertion surface
	 * on the final state rather than a specific scheduling order.
	 *
	 * @param string[]    $hooks          Hooks to drain.
	 * @param string|null $group          Optional action group.
	 * @param int         $max_iterations Safety cap to avoid infinite loops.
	 * @return int Total number of actions processed.
	 */
	public static function run_until_idle( array $hooks, ?string $group = null, int $max_iterations = 50 ): int {
		$total      = 0;
		$iterations = 0;

		do {
			$processed_this_round = 0;

			foreach ( $hooks as $hook ) {
				$processed_this_round += self::run_all_pending( $hook, $group );
			}

			$total += $processed_this_round;
			++$iterations;
		} while ( 0 !== $processed_this_round && $iterations < $max_iterations );

		return $total;
	}
}
