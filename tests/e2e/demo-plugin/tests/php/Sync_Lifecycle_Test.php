<?php
/**
 * Verifies the library's sync-lifecycle hooks fire correctly during real
 * Action Scheduler execution.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Product_CSV_Import;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;

/**
 * @group lifecycle
 */
class Sync_Lifecycle_Test extends E2E_Test_Case {

	public function test_lifecycle_hooks_fire_during_real_execution(): void {
		$start_calls    = 0;
		$complete_calls = 0;
		$finish_calls   = 0;

		add_action(
			Product_CSV_Import::SYNC_NAME . '/start',
			static function () use ( &$start_calls ): void {
				++$start_calls;
			},
			10,
			2
		);

		add_action(
			Product_CSV_Import::SYNC_NAME . '/complete',
			static function () use ( &$complete_calls ): void {
				++$complete_calls;
			},
			10,
			2
		);

		add_action(
			Product_CSV_Import::SYNC_NAME . '/finish',
			static function () use ( &$finish_calls ): void {
				++$finish_calls;
			}
		);

		$root_action_id = $this->schedule_import( Product_CSV_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Product_CSV_Import::SYNC_NAME,
			$root_action_id
		);
		$this->run_sync_to_completion( Product_CSV_Import::SYNC_NAME, $group );

		// 1 root action + 3 chunk actions = at least 4 start/complete firings.
		$this->assertGreaterThanOrEqual( 4, $start_calls );
		$this->assertGreaterThanOrEqual( 4, $complete_calls );
		// /finish fires exactly once when the whole sync is done.
		$this->assertSame( 1, $finish_calls );

		remove_all_actions( Product_CSV_Import::SYNC_NAME . '/start' );
		remove_all_actions( Product_CSV_Import::SYNC_NAME . '/complete' );
		remove_all_actions( Product_CSV_Import::SYNC_NAME . '/finish' );
	}
}
