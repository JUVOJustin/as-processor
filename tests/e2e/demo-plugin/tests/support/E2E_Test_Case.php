<?php
/**
 * Shared base class for AS Processor application tests.
 *
 * Extends WP_UnitTestCase so tests get the full WordPress test harness
 * (factory, cleanup between tests, transactional DB). Adds helpers that
 * schedule imports, drive the real Action Scheduler queue, and assert on
 * the library's chunk-tracking table.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Support;

use ActionScheduler_Store;
use AS_Processor_Demo\Lead_JSON_Import;
use AS_Processor_Demo\Product_API_Import;
use AS_Processor_Demo\Product_CSV_Import;
use AS_Processor_Demo\Product_Excel_Import;
use AS_Processor_Demo\Support\Demo_Fixture_Manager;
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\DB\Data_DB;
use WP_UnitTestCase;

abstract class E2E_Test_Case extends WP_UnitTestCase {

	/**
	 * Reset the environment before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		Demo_Fixture_Manager::cleanup_runtime_copies();
		$this->cleanup_tracking_tables();
		$this->cleanup_pending_actions();
	}

	/**
	 * Clean up after each test to keep state from leaking.
	 */
	public function tear_down(): void {
		$this->cleanup_pending_actions();
		$this->cleanup_tracking_tables();
		Demo_Fixture_Manager::cleanup_runtime_copies();

		parent::tear_down();
	}

	/**
	 * Truncate the library's chunk- and sync-data tables so each test
	 * starts from a known-empty state.
	 */
	protected function cleanup_tracking_tables(): void {
		global $wpdb;

		Chunk_DB::db()->ensure_table();
		Data_DB::db()->ensure_table();

		$wpdb->query( 'DELETE FROM ' . Chunk_DB::db()->get_table_name() );
		$wpdb->query( 'DELETE FROM ' . Data_DB::db()->get_table_name() );
	}

	/**
	 * Remove any pending Action Scheduler actions for the demo imports.
	 */
	protected function cleanup_pending_actions(): void {
		foreach ( $this->sync_hooks() as $hook ) {
			as_unschedule_all_actions( $hook );
			as_unschedule_all_actions( $hook . '/process_chunk' );
		}
	}

	/**
	 * Schedule an import's root action and assert it was queued.
	 *
	 * @param string $hook Sync name / root hook.
	 * @return int Action Scheduler action ID of the root action.
	 */
	protected function schedule_import( string $hook ): int {
		$action_id = as_enqueue_async_action( $hook );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertSame(
			array( $action_id ),
			Action_Scheduler_Test_Helper::get_pending_action_ids( $hook )
		);

		return $action_id;
	}

	/**
	 * Execute the root action and return the sync group it produced.
	 *
	 * The library generates the sync group name at dispatch time
	 * ({sync_name}_{timestamp}), so tests read it back from the chunks
	 * table after the root action has run.
	 *
	 * @param string $hook      Sync name / root hook.
	 * @param int    $action_id Root action ID.
	 * @return string Sync group name.
	 */
	protected function run_root_action_and_get_group( string $hook, int $action_id ): string {
		Action_Scheduler_Test_Helper::run_action( $action_id );

		$group = $this->get_latest_chunk_group_for_sync( $hook );

		$this->assertNotSame(
			'',
			$group,
			sprintf( 'Root action for %s did not create a chunk group.', $hook )
		);

		return $group;
	}

	/**
	 * Drain every pending action belonging to a sync through the real
	 * Action Scheduler queue runner.
	 *
	 * @param string $hook  Sync name / root hook.
	 * @param string $group Sync group name (used to scope the queries).
	 */
	protected function run_sync_to_completion( string $hook, string $group ): void {
		$processed = Action_Scheduler_Test_Helper::run_until_idle(
			array( $hook, $hook . '/process_chunk' ),
			$group
		);

		$this->assertGreaterThan( 0, $processed, 'Expected scheduled actions to run.' );
		$this->assertSame(
			array(),
			Action_Scheduler_Test_Helper::get_pending_action_ids( $hook, $group )
		);
		$this->assertSame(
			array(),
			Action_Scheduler_Test_Helper::get_pending_action_ids( $hook . '/process_chunk', $group )
		);
	}

	/**
	 * Return the most recent chunk group created for a sync.
	 *
	 * @param string $hook Sync name.
	 * @return string
	 */
	protected function get_latest_chunk_group_for_sync( string $hook ): string {
		global $wpdb;

		$table = Chunk_DB::db()->get_table_name();
		$group = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `group` FROM {$table} WHERE `group` LIKE %s ORDER BY id DESC LIMIT 1",
				$hook . '_%'
			)
		);

		return is_string( $group ) ? $group : '';
	}

	/**
	 * Assert every tracked chunk in a sync finished successfully.
	 *
	 * @param string $group Sync group name.
	 */
	protected function assert_sync_finished( string $group ): void {
		global $wpdb;

		$table = Chunk_DB::db()->get_table_name();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE `group` = %s", $group )
		);

		$finished = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE `group` = %s AND status = %s",
				$group,
				'finished'
			)
		);

		$this->assertGreaterThan( 0, $total, 'Expected chunks to be tracked for the sync.' );
		$this->assertSame( $total, $finished, 'All chunks should reach the finished state.' );
	}

	/**
	 * Count published posts for a post type.
	 */
	protected function get_post_count( string $post_type ): int {
		return (int) ( wp_count_posts( $post_type )->publish ?? 0 );
	}

	/**
	 * Return scheduled action IDs for a hook, status, and optional group.
	 *
	 * @return int[]
	 */
	protected function get_action_ids(
		string $hook,
		string $status = ActionScheduler_Store::STATUS_PENDING,
		?string $group = null
	): array {
		$query = array(
			'hook'     => $hook,
			'status'   => $status,
			'per_page' => 100,
		);

		if ( null !== $group ) {
			$query['group'] = $group;
		}

		return array_map( 'intval', as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * All root sync hooks the demo plugin exposes.
	 *
	 * @return string[]
	 */
	private function sync_hooks(): array {
		return array(
			Product_CSV_Import::SYNC_NAME,
			Lead_JSON_Import::SYNC_NAME,
			Product_Excel_Import::SYNC_NAME,
			Product_API_Import::SYNC_NAME,
		);
	}
}
