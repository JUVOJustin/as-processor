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
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\Entities\ProcessStatus;

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
}
