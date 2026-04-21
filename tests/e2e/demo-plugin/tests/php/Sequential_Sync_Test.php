<?php
/**
 * Covers the sequential sync workflow end-to-end.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Combined_Sequential_Import;
use AS_Processor_Demo\Sequential_Lead_JSON_Import;
use AS_Processor_Demo\Sequential_Product_CSV_Import;
use AS_Processor_Demo\Tests\Support\Action_Scheduler_Test_Helper;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;
use juvo\AS_Processor\DB\Data_DB;

/**
 * @group lifecycle
 */
class Sequential_Sync_Test extends E2E_Test_Case {

	public function test_sequential_sync_runs_jobs_in_order_through_real_queue(): void {
		$csv_finish_calls = 0;
		$json_start_calls = 0;

		add_action(
			Sequential_Product_CSV_Import::SYNC_NAME . '/finish',
			static function () use ( &$csv_finish_calls ): void {
				++$csv_finish_calls;
			}
		);

		add_action(
			Sequential_Lead_JSON_Import::SYNC_NAME . '/start',
			function () use ( &$json_start_calls ): void {
				++$json_start_calls;
				$this->assertSame( 15, $this->get_post_count( 'asp_product' ), 'JSON sync must not start before the CSV sync fully finishes.' );
			},
			10,
			2
		);

		$this->schedule_import( Combined_Sequential_Import::SYNC_NAME );

		$processed = Action_Scheduler_Test_Helper::run_until_idle(
			array(
				Combined_Sequential_Import::SYNC_NAME,
				Sequential_Product_CSV_Import::SYNC_NAME,
				Sequential_Product_CSV_Import::SYNC_NAME . '/process_chunk',
				Sequential_Lead_JSON_Import::SYNC_NAME,
				Sequential_Lead_JSON_Import::SYNC_NAME . '/process_chunk',
			),
			null,
			100
		);

		$this->assertGreaterThan( 0, $processed, 'Expected sequential sync to process queued child actions.' );
		$this->assertSame( 15, $this->get_post_count( 'asp_product' ) );
		$this->assertSame( 12, $this->get_post_count( 'asp_lead' ) );
		$this->assertSame( 1, $csv_finish_calls, 'CSV job should finish exactly once.' );
		$this->assertGreaterThanOrEqual( 1, $json_start_calls, 'JSON job should start after CSV completion.' );
		$this->assertSame(
			15,
			(int) Data_DB::db()->get( Combined_Sequential_Import::SYNC_NAME . '_' . Sequential_Product_CSV_Import::PRODUCT_COUNT_KEY, 'data' ),
			'The sequence should persist the CSV product count in shared sync data.'
		);
		$this->assertSame(
			15,
			(int) Data_DB::db()->get( Combined_Sequential_Import::SYNC_NAME . '_' . Sequential_Lead_JSON_Import::OBSERVED_PRODUCT_COUNT_KEY, 'data' ),
			'The JSON job should observe the CSV-produced shared sync data through the sequential handoff.'
		);

		remove_all_actions( Sequential_Product_CSV_Import::SYNC_NAME . '/finish' );
		remove_all_actions( Sequential_Lead_JSON_Import::SYNC_NAME . '/start' );
	}

	public function test_sequential_sync_shares_sync_data_between_jobs(): void {
		$this->schedule_import( Combined_Sequential_Import::SYNC_NAME );

		Action_Scheduler_Test_Helper::run_until_idle(
			array(
				Combined_Sequential_Import::SYNC_NAME,
				Sequential_Product_CSV_Import::SYNC_NAME,
				Sequential_Product_CSV_Import::SYNC_NAME . '/process_chunk',
				Sequential_Lead_JSON_Import::SYNC_NAME,
				Sequential_Lead_JSON_Import::SYNC_NAME . '/process_chunk',
			),
			null,
			100
		);

		$this->assertSame(
			15,
			(int) Data_DB::db()->get( Combined_Sequential_Import::SYNC_NAME . '_' . Sequential_Product_CSV_Import::PRODUCT_COUNT_KEY, 'data' )
		);

		$this->assertSame(
			15,
			(int) Data_DB::db()->get( Combined_Sequential_Import::SYNC_NAME . '_' . Sequential_Lead_JSON_Import::OBSERVED_PRODUCT_COUNT_KEY, 'data' )
		);
	}
}
