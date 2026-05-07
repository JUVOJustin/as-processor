<?php
/**
 * CSV import variant used only inside the sequential demo workflow.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

class Sequential_Product_CSV_Import extends Product_CSV_Import {

	public const SYNC_NAME         = 'asp_demo_sequence_product_csv_import';
	public const PRODUCT_COUNT_KEY = 'sequence_csv_product_count';

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	public function on_finish(): void {
		$this->get_shared_sync_data_store()->update( self::PRODUCT_COUNT_KEY, (int) ( wp_count_posts( 'asp_product' )->publish ?? 0 ) );
	}
}
