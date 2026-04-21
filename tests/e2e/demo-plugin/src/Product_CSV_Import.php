<?php
/**
 * Imports products from a CSV fixture into the asp_product CPT.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use AS_Processor_Demo\Support\Demo_Fixture_Manager;
use Generator;
use juvo\AS_Processor\Imports\CSV;

class Product_CSV_Import extends CSV {

	public const SYNC_NAME = 'asp_demo_product_csv_import';

	protected int $chunk_size    = 5;
	protected string $delimiter  = ',';
	protected bool $has_header   = true;

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	/**
	 * Dispatch the root action that kicks off the sync.
	 */
	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	/**
	 * Each import gets its own copy so the library can delete the source
	 * without breaking the next test.
	 */
	protected function get_source_path(): string {
		return Demo_Fixture_Manager::create_runtime_copy( 'products.csv' );
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
		foreach ( $chunk_data as $row ) {
			$this->upsert_product( $row );
		}
	}

	private function upsert_product( array $data ): void {
		$sku = (string) ( $data['sku'] ?? '' );

		$existing = get_posts(
			array(
				'post_type'      => 'asp_product',
				'meta_key'       => 'sku',
				'meta_value'     => $sku,
				'posts_per_page' => 1,
			)
		);

		$post_data = array(
			'post_type'   => 'asp_product',
			'post_title'  => (string) ( $data['name'] ?? 'Untitled Product' ),
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

		update_post_meta( $post_id, 'sku', $sku );
		update_post_meta( $post_id, 'price', (float) ( $data['price'] ?? 0 ) );
		update_post_meta( $post_id, 'category', (string) ( $data['category'] ?? '' ) );
		update_post_meta( $post_id, 'stock', (int) ( $data['stock'] ?? 0 ) );
	}
}
