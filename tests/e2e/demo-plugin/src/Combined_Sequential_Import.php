<?php
/**
 * Runs the demo CSV and JSON imports in sequence.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use juvo\AS_Processor\Sequential_Sync;
use juvo\AS_Processor\Sync;

class Combined_Sequential_Import extends Sequential_Sync {

	public const SYNC_NAME = 'asp_demo_combined_sequential_import';

	public function __construct() {
		parent::__construct();
		$this->queue_init();
	}

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	/**
	 * @return Sync[]
	 */
	protected function get_jobs(): array {
		return array(
			new Sequential_Product_CSV_Import(),
			new Sequential_Lead_JSON_Import(),
		);
	}

	protected function process_chunk( int $chunk_id ): void {
	}

	protected function process_chunk_data( \Generator $chunk_data ): void {
	}
}
