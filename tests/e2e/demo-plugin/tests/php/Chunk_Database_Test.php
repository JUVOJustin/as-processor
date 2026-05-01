<?php
/**
 * Verifies the library's chunk-tracking table reflects real import
 * executions and that the stats helpers return sensible values.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Product_CSV_Import;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;
use juvo\AS_Processor\AS_Processor;
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\Entities\ProcessStatus;
use WP_Hook;

/**
 * @group database
 */
class Chunk_Database_Test extends E2E_Test_Case {

	public function test_chunk_tracking_reflects_real_import_execution(): void {
		$root_action_id = $this->schedule_import( Product_CSV_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Product_CSV_Import::SYNC_NAME,
			$root_action_id
		);
		$this->run_sync_to_completion( Product_CSV_Import::SYNC_NAME, $group );

		$this->assertCount( 3, Chunk_DB::db()->get_chunks_by_status( $group ) );
		$this->assertCount( 3, Chunk_DB::db()->get_chunks_by_status( $group, ProcessStatus::FINISHED ) );
		$this->assertTrue( Chunk_DB::db()->are_all_chunks_completed( $group ) );
		$this->assertSame( 3, Chunk_DB::db()->get_total_actions( $group ) );
		$this->assertGreaterThanOrEqual( 0.0, (float) Chunk_DB::db()->get_average_action_duration( $group ) );
		$this->assertNotFalse( Chunk_DB::db()->get_sync_duration( $group ) );
		$this->assertNotNull( Chunk_DB::db()->get_fastest_action( $group ) );
		$this->assertNotNull( Chunk_DB::db()->get_slowest_action( $group ) );
	}

	public function test_asp_cleanup_action_removes_only_old_chunks_for_the_filtered_status(): void {
		global $wpdb;

		$table = Chunk_DB::db()->get_table_name();

		$old_finished_group    = 'cleanup_old_finished';
		$recent_finished_group = 'cleanup_recent_finished';
		$old_failed_group      = 'cleanup_old_failed';

		$old_start        = (string) ( time() - ( 2 * DAY_IN_SECONDS ) );
		$recent_start     = (string) time();
		$old_created_at   = gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) );
		$recent_created_at = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			$table,
			array(
				'group'      => $old_finished_group,
				'status'     => ProcessStatus::FINISHED->value,
				'data'       => wp_json_encode( array( 'row' => 'old-finished' ) ),
				'start'      => $old_start,
				'end'        => $old_start,
				'created_at' => $old_created_at,
			),
		);

		$wpdb->insert(
			$table,
			array(
				'group'      => $recent_finished_group,
				'status'     => ProcessStatus::FINISHED->value,
				'data'       => wp_json_encode( array( 'row' => 'recent-finished' ) ),
				'start'      => $recent_start,
				'end'        => $recent_start,
				'created_at' => $recent_created_at,
			),
		);

		$wpdb->insert(
			$table,
			array(
				'group'      => $old_failed_group,
				'status'     => ProcessStatus::FAILED->value,
				'data'       => wp_json_encode( array( 'row' => 'old-failed' ) ),
				'start'      => $old_start,
				'end'        => $old_start,
				'created_at' => $old_created_at,
			),
		);

		$interval_filter = static fn (): int => DAY_IN_SECONDS;
		$status_filter   = static fn (): ProcessStatus => ProcessStatus::FINISHED;

		add_filter( 'asp/chunks/cleanup/interval', $interval_filter );
		add_filter( 'asp/chunks/cleanup/status', $status_filter );

		$this->assertNotFalse( has_action( 'asp/cleanup' ) );

		do_action( 'asp/cleanup' );

		remove_filter( 'asp/chunks/cleanup/interval', $interval_filter );
		remove_filter( 'asp/chunks/cleanup/status', $status_filter );

		$this->assertSame( 0, Chunk_DB::db()->get_total_actions( $old_finished_group ) );
		$this->assertSame( 1, Chunk_DB::db()->get_total_actions( $recent_finished_group ) );
		$this->assertSame( 1, Chunk_DB::db()->get_total_actions( $old_failed_group ) );
	}

	public function test_action_scheduler_ensure_recurring_actions_schedules_asp_cleanup(): void {
		as_unschedule_all_actions( 'asp/cleanup' );

		$this->assertFalse( as_has_scheduled_action( 'asp/cleanup' ) );

		do_action( 'action_scheduler_ensure_recurring_actions' );

		$this->assertTrue( as_has_scheduled_action( 'asp/cleanup' ) );

		as_unschedule_all_actions( 'asp/cleanup' );
	}

	public function test_as_processor_register_is_idempotent(): void {
		$cleanup_callbacks   = $this->count_registered_callbacks( 'asp/cleanup' );
		$recurring_callbacks = $this->count_registered_callbacks( 'action_scheduler_ensure_recurring_actions' );

		AS_Processor::register();
		AS_Processor::register();

		$this->assertSame( $cleanup_callbacks, $this->count_registered_callbacks( 'asp/cleanup' ) );
		$this->assertSame( $recurring_callbacks, $this->count_registered_callbacks( 'action_scheduler_ensure_recurring_actions' ) );
		$this->assertSame( 10, has_action( 'asp/cleanup', array( AS_Processor::class, 'cleanup' ) ) );
		$this->assertSame( 10, has_action( 'action_scheduler_ensure_recurring_actions', array( AS_Processor::class, 'ensure_recurring_actions' ) ) );
	}

	private function count_registered_callbacks( string $hook ): int {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return 0;
		}

		$count = 0;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}
}
