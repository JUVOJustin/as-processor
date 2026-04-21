<?php
/**
 * Imports products from the mock REST endpoint into the asp_api_item CPT.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use Exception;
use Generator;
use juvo\AS_Processor\Imports\API;

class Product_API_Import extends API {

	public const SYNC_NAME = 'asp_demo_product_api_import';

	public int $chunk_size                  = 5;
	protected string|int $index             = 1;
	protected float $time_between_requests  = 0.1;

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	/**
	 * Reset pagination state when the root action runs without an explicit
	 * index so repeated dispatches of the same import instance (e.g. during
	 * integration tests) start from page 1 again.
	 */
	public function split_data_into_chunks( ?int $index = null ): void {
		if ( null === $index ) {
			$this->index = 1;
			$this->next  = 0;
		}

		parent::split_data_into_chunks( $index );
	}

	/**
	 * Fetch a page of products from the in-process mock REST endpoint.
	 *
	 * Uses rest_do_request() instead of wp_remote_get() so the call stays
	 * in-process during tests (no HTTP roundtrip required, no server
	 * configuration needed in CI).
	 */
	protected function process_fetch(): array {
		$request  = new \WP_REST_Request( 'GET', sprintf( '/as-processor-demo/v1/products/%d', (int) $this->index ) );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			throw new Exception(
				sprintf( 'Mock REST endpoint returned an error: %s', (string) $response->as_error()->get_error_message() )
			);
		}

		$body = $response->get_data();

		$pagination = $body['pagination'] ?? array();

		if ( ! empty( $pagination['has_next'] ) ) {
			$this->set_next_page( (int) $pagination['total_pages'] );
		} else {
			$this->set_next_page( (int) $this->index );
		}

		return (array) ( $body['data'] ?? array() );
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
		foreach ( $chunk_data as $product ) {
			$this->upsert_product( $product );
		}
	}

	private function upsert_product( array $data ): void {
		$api_id = (string) ( $data['id'] ?? '' );

		$existing = get_posts(
			array(
				'post_type'      => 'asp_api_item',
				'meta_key'       => 'api_id',
				'meta_value'     => $api_id,
				'posts_per_page' => 1,
			)
		);

		$post_data = array(
			'post_type'   => 'asp_api_item',
			'post_title'  => (string) ( $data['name'] ?? 'Untitled API Item' ),
			'post_status' => 'publish',
		);

		if ( ! empty( $existing ) ) {
			$post_data['ID'] = $existing[0]->ID;
			$post_id         = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, 'api_id', $api_id );
		update_post_meta( $post_id, 'api_data', wp_json_encode( $data ) );
	}
}
