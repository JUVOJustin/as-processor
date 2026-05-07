<?php
/**
 * Covers the paginated API import workflow end-to-end.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use ActionScheduler_Store;
use AS_Processor_Demo\Product_API_Import;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;
use juvo\AS_Processor\DB\Chunk_DB;
use WP_REST_Request;

/**
 * @group api
 */
class API_Import_Test extends E2E_Test_Case {

	public function test_demo_rest_endpoint_returns_expected_payload(): void {
		$request  = new WP_REST_Request( 'GET', '/as-processor-demo/v1/products/1' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 10, $data['data'] );
		$this->assertSame( 35, $data['pagination']['total_items'] );
		$this->assertTrue( $data['pagination']['has_next'] );
	}

	public function test_api_import_runs_through_real_queue(): void {
		$root_action_id = $this->schedule_import( Product_API_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Product_API_Import::SYNC_NAME,
			$root_action_id
		);

		// The first API action should use its request budget to fetch all four
		// mock pages and leave only chunk processors queued.
		$this->assertCount(
			0,
			$this->get_action_ids( Product_API_Import::SYNC_NAME, 'pending', $group )
		);
		$this->assertCount(
			7,
			$this->get_action_ids( Product_API_Import::SYNC_NAME . '/process_chunk', 'pending', $group )
		);

		$this->run_sync_to_completion( Product_API_Import::SYNC_NAME, $group );
		$this->assert_sync_finished( $group );
		$this->assertSame( 7, Chunk_DB::db()->get_total_actions( $group ) );
		$this->assertSame( 35, $this->get_post_count( 'asp_api_item' ) );
	}

	public function test_api_import_schedules_continuation_when_batch_limit_is_reached(): void {
		$batch_limit_filter = static fn (): int => 2;

		add_filter( 'asp/api/max_fetches_per_request', $batch_limit_filter );

		try {
			$root_action_id = $this->schedule_import( Product_API_Import::SYNC_NAME );
			$group          = $this->run_root_action_and_get_group(
				Product_API_Import::SYNC_NAME,
				$root_action_id
			);

			$fetch_action_ids = $this->get_action_ids( Product_API_Import::SYNC_NAME, 'pending', $group );
			$this->assertCount( 1, $fetch_action_ids );

			$fetch_action = ActionScheduler_Store::instance()->fetch_action( (string) $fetch_action_ids[0] );
			$this->assertSame( 3, $fetch_action->get_args()['index'] );

			$this->assertCount(
				4,
				$this->get_action_ids( Product_API_Import::SYNC_NAME . '/process_chunk', 'pending', $group )
			);

			$this->run_sync_to_completion( Product_API_Import::SYNC_NAME, $group );
			$this->assert_sync_finished( $group );
			$this->assertSame( 7, Chunk_DB::db()->get_total_actions( $group ) );
			$this->assertSame( 35, $this->get_post_count( 'asp_api_item' ) );
		} finally {
			remove_filter( 'asp/api/max_fetches_per_request', $batch_limit_filter );
		}
	}

	public function test_api_import_persists_unique_items_with_serialized_payloads(): void {
		$root_action_id = $this->schedule_import( Product_API_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Product_API_Import::SYNC_NAME,
			$root_action_id
		);
		$this->run_sync_to_completion( Product_API_Import::SYNC_NAME, $group );

		$items = get_posts(
			array(
				'post_type'      => 'asp_api_item',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertCount( 35, $items );

		$api_ids = array_map(
			static fn( int $post_id ): string => (string) get_post_meta( $post_id, 'api_id', true ),
			$items
		);

		$this->assertCount( 35, array_unique( $api_ids ) );

		$first_payload = json_decode( (string) get_post_meta( (int) $items[0], 'api_data', true ), true );
		$this->assertIsArray( $first_payload );
		$this->assertArrayHasKey( 'sku', $first_payload );
	}
}
