<?php
/**
 * Test-only sync used to exercise parallel finish and sync-data writes.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Support;

use ActionScheduler_Action;
use Generator;
use juvo\AS_Processor\Sync;

class Parallel_Finish_Test_Sync extends Sync {

	public const SYNC_NAME           = 'asp_test_parallel_finish_sync';
	private const WRITES_KEY         = 'parallel_writes';
	private const FINISH_ATTEMPTS_KEY = 'finish_attempts';
	private const FINISH_GROUPS_KEY   = 'finish_groups';

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
			$this->get_shared_sync_data_store()->update(
				self::WRITES_KEY,
				array( (string) ( $row['label'] ?? '' ) ),
				false,
				true
			);
		}
	}

	protected function should_trigger_finish( ActionScheduler_Action $action ): bool {
		return ! empty( $action->get_group() );
	}

	protected function mark_finish_ready(): bool {
		$this->get_shared_sync_data_store()->update(
			self::FINISH_ATTEMPTS_KEY,
			array( $this->action_id ),
			false,
			true
		);

		return parent::mark_finish_ready();
	}

	public function on_finish(): void {
		$this->get_shared_sync_data_store()->update(
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
		return array_values( (array) ( $this->get_shared_sync_data_store()->get( self::WRITES_KEY ) ?: array() ) );
	}

	/**
	 * Return action IDs that attempted to mark finish ready.
	 *
	 * @return int[]
	 */
	public function get_finish_attempts(): array {
		return array_map(
			'intval',
			array_values( (array) ( $this->get_shared_sync_data_store()->get( self::FINISH_ATTEMPTS_KEY ) ?: array() ) )
		);
	}

	/**
	 * Return groups recorded by the finish hook.
	 *
	 * @return string[]
	 */
	public function get_finish_groups(): array {
		return array_values( (array) ( $this->get_shared_sync_data_store()->get( self::FINISH_GROUPS_KEY ) ?: array() ) );
	}
}
