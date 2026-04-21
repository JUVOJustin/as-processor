<?php
/**
 * Covers the paginated API import workflow end-to-end.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Product_API_Import;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;
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

		// After the first fetch completes there should be at least one follow-up
		// fetch queued and one chunk scheduled from the initial page.
		$this->assertGreaterThanOrEqual(
			1,
			count( $this->get_action_ids( Product_API_Import::SYNC_NAME, 'pending', $group ) )
		);
		$this->assertGreaterThanOrEqual(
			1,
			count( $this->get_action_ids( Product_API_Import::SYNC_NAME . '/process_chunk', 'pending', $group ) )
		);

		$this->run_sync_to_completion( Product_API_Import::SYNC_NAME, $group );
		$this->assert_sync_finished( $group );
		$this->assertSame( 35, $this->get_post_count( 'asp_api_item' ) );
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
