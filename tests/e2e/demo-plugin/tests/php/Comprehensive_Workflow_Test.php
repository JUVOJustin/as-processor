<?php
/**
 * Runs all four demo imports in a single test so the library surface is
 * exercised together.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Lead_JSON_Import;
use AS_Processor_Demo\Product_API_Import;
use AS_Processor_Demo\Product_CSV_Import;
use AS_Processor_Demo\Product_Excel_Import;
use AS_Processor_Demo\Tests\Support\Action_Scheduler_Test_Helper;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;

/**
 * @group comprehensive
 */
class Comprehensive_Workflow_Test extends E2E_Test_Case {

	public function test_all_demo_imports_complete_without_leaking_state(): void {
		$csv_root   = $this->schedule_import( Product_CSV_Import::SYNC_NAME );
		$json_root  = $this->schedule_import( Lead_JSON_Import::SYNC_NAME );
		$excel_root = $this->schedule_import( Product_Excel_Import::SYNC_NAME );
		$api_root   = $this->schedule_import( Product_API_Import::SYNC_NAME );

		$csv_group   = $this->run_root_action_and_get_group( Product_CSV_Import::SYNC_NAME, $csv_root );
		$json_group  = $this->run_root_action_and_get_group( Lead_JSON_Import::SYNC_NAME, $json_root );
		$excel_group = $this->run_root_action_and_get_group( Product_Excel_Import::SYNC_NAME, $excel_root );
		$api_group   = $this->run_root_action_and_get_group( Product_API_Import::SYNC_NAME, $api_root );

		$hooks = array(
			Product_CSV_Import::SYNC_NAME,
			Product_CSV_Import::SYNC_NAME . '/process_chunk',
			Lead_JSON_Import::SYNC_NAME,
			Lead_JSON_Import::SYNC_NAME . '/process_chunk',
			Product_Excel_Import::SYNC_NAME,
			Product_Excel_Import::SYNC_NAME . '/process_chunk',
			Product_API_Import::SYNC_NAME,
			Product_API_Import::SYNC_NAME . '/process_chunk',
		);

		$processed = Action_Scheduler_Test_Helper::run_until_idle_parallel(
			$hooks,
			null,
			3
		);

		$this->assertGreaterThan( 0, $processed, 'Expected scheduled actions to run.' );

		// CSV adds 15 products, Excel adds 10 more — no overlap because the
		// SKU keys (SKU-* vs XLS-*) are disjoint.
		$this->assertSame( 25, $this->get_post_count( 'asp_product' ) );
		$this->assertSame( 12, $this->get_post_count( 'asp_lead' ) );
		$this->assertSame( 35, $this->get_post_count( 'asp_api_item' ) );

		$this->assert_sync_finished( $csv_group );
		$this->assert_sync_finished( $json_group );
		$this->assert_sync_finished( $excel_group );
		$this->assert_sync_finished( $api_group );
	}
}
