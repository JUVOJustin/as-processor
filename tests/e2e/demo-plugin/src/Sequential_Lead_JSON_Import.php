<?php
/**
 * JSON import variant used only inside the sequential demo workflow.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

class Sequential_Lead_JSON_Import extends Lead_JSON_Import {

	public const SYNC_NAME                  = 'asp_demo_sequence_lead_json_import';
	public const OBSERVED_PRODUCT_COUNT_KEY = 'sequence_json_observed_csv_product_count';
	public const OBSERVED_AT_START_KEY      = 'sequence_json_observed_csv_product_count_at_start';

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	public function track_scheduling_action( \ActionScheduler_Action $action ): void {
		parent::track_scheduling_action( $action );

		if ( $this->get_shared_sync_data_store()->get( self::OBSERVED_AT_START_KEY ) ) {
			return;
		}

		$csv_product_count = (int) $this->get_shared_sync_data_store()->get( Sequential_Product_CSV_Import::PRODUCT_COUNT_KEY );

		$this->get_shared_sync_data_store()->update( self::OBSERVED_PRODUCT_COUNT_KEY, $csv_product_count );
		$this->get_shared_sync_data_store()->update( self::OBSERVED_AT_START_KEY, true );
	}
}
