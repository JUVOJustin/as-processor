<?php
/**
 * Test-only sync used to exercise parallel finish and sync-data writes.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Support;

use Generator;
use juvo\AS_Processor\Sync;

class Parallel_Finish_Test_Sync extends Sync {

	public const SYNC_NAME          = 'asp_test_parallel_finish_sync';
	private const WRITES_KEY        = 'parallel_writes';
	private const FINISH_GROUPS_KEY = 'finish_groups';

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		$this->schedule_chunk(
			array(
				array(
					'label' => 'first',
				),
			)
		);
		$this->schedule_chunk(
			array(
				array(
					'label' => 'second',
				),
			)
		);
	}

	public function process_chunk( int $chunk_id ): void {
		$this->import_chunk( $chunk_id );
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
		foreach ( $chunk_data as $row ) {
			$this->update_sync_data(
				self::WRITES_KEY,
				array( (string) ( $row['label'] ?? '' ) ),
				false,
				true
			);
		}
	}

	public function on_finish(): void {
		$this->update_sync_data(
			self::FINISH_GROUPS_KEY,
			array( $this->get_sync_group_name() ),
			false,
			true
		);
	}

	/**
	 * Return labels written by parallel chunk workers.
	 *
	 * @return string[]
	 */
	public function get_parallel_writes(): array {
		return array_values( (array) ( $this->get_sync_data( self::WRITES_KEY ) ?: array() ) );
	}

	/**
	 * Return groups recorded by the finish hook. One entry per finish firing.
	 *
	 * @return string[]
	 */
	public function get_finish_groups(): array {
		return array_values( (array) ( $this->get_sync_data( self::FINISH_GROUPS_KEY ) ?: array() ) );
	}
}
