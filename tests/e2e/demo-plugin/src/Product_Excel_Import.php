<?php
/**
 * Imports products from an XLSX fixture into the asp_product CPT.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use AS_Processor_Demo\Support\Demo_Fixture_Manager;
use Generator;
use juvo\AS_Processor\Imports\Excel;

class Product_Excel_Import extends Excel {

	public const SYNC_NAME = 'asp_demo_product_excel_import';

	protected int $chunk_size    = 5;
	protected bool $has_header   = true;
	protected bool $skip_empty_rows = true;

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	public function get_source_path(): string {
		return Demo_Fixture_Manager::create_runtime_copy( 'products.xlsx' );
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
		foreach ( $chunk_data as $row ) {
			$this->upsert_product( $row );
		}
	}

	private function upsert_product( array $data ): void {
		$sku = (string) ( $data['SKU'] ?? $data['sku'] ?? '' );

		$existing = get_posts(
			array(
				'post_type'      => 'asp_product',
				'meta_key'       => 'sku',
				'meta_value'     => $sku,
				'posts_per_page' => 1,
			)
		);

		$title = (string) ( $data['Product Name'] ?? $data['Name'] ?? $data['name'] ?? 'Untitled Product' );

		$post_data = array(
			'post_type'   => 'asp_product',
			'post_title'  => $title,
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
		update_post_meta( $post_id, 'price', (float) ( $data['Price'] ?? $data['price'] ?? 0 ) );
		update_post_meta( $post_id, 'category', (string) ( $data['Category'] ?? $data['category'] ?? '' ) );
		update_post_meta( $post_id, 'stock', (int) ( $data['Stock'] ?? $data['stock'] ?? 0 ) );
	}
}
