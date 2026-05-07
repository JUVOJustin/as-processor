<?php
/**
 * Regression coverage for action tracking behavior introduced around 3.3.0.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Tests\Support\Action_Scheduler_Test_Helper;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;
use AS_Processor_Demo\Tests\Support\Parallel_Finish_Test_Sync;
use Exception;
use Generator;
use juvo\AS_Processor\Entities\Chunk;
use juvo\AS_Processor\Entities\ProcessStatus;
use juvo\AS_Processor\Sequential_Sync;
use juvo\AS_Processor\Sync;

/**
 * @group lifecycle
 */
class Action_Tracking_Regression_Test extends E2E_Test_Case {

	public function test_sync_base_class_fires_finish_after_last_action_completes(): void {
		$sync           = $this->create_sync_double( 'asp_test_direct_sync' );
		$complete_calls = 0;
		$finish_calls   = 0;

		add_action(
			$sync->get_sync_name() . '/complete',
			static function () use ( &$complete_calls ): void {
				++$complete_calls;
			},
			10,
			2
		);

		add_action(
			$sync->get_sync_name() . '/finish',
			static function () use ( &$finish_calls ): void {
				++$finish_calls;
			}
		);

		$group     = $sync->get_sync_group_name();
		$action_id = as_enqueue_async_action( $sync->get_sync_name() . '/process_chunk', array( 123 ), $group );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );

		Action_Scheduler_Test_Helper::run_action( $action_id );

		$this->assertSame( 1, $complete_calls, 'The per-action complete hook should fire for a direct Sync action.' );
		$this->assertSame( 1, $finish_calls, 'The finish hook should fire once after the final direct Sync action completes.' );
	}

	public function test_delete_tracking_ignores_chunks_owned_by_other_syncs(): void {
		$wrong_sync        = $this->create_sync_double( 'asp_test_wrong_sync' );
		$owning_sync       = $this->create_sync_double( 'asp_test_owning_sync' );
		$wrong_delete_hits = 0;
		$right_delete_hits = 0;

		add_action(
			$wrong_sync->get_sync_name() . '/delete',
			static function () use ( &$wrong_delete_hits ): void {
				++$wrong_delete_hits;
			}
		);

		add_action(
			$owning_sync->get_sync_name() . '/delete',
			static function () use ( &$right_delete_hits ): void {
				++$right_delete_hits;
			}
		);

		$action_id = 987654;
		$group     = $owning_sync->get_sync_group_name();
		$chunk     = new Chunk();

		$chunk->set_action_id( $action_id );
		$chunk->set_group( $group );
		$chunk->set_status( ProcessStatus::SCHEDULED );
		$chunk->set_data( array( 'row' => 'owned-by-other-sync' ) );
		$chunk_id = $chunk->save();

		$wrong_sync->handle_delete( $action_id );

		$stored_chunk = new Chunk( $chunk_id );

		$this->assertSame( 0, $wrong_delete_hits, 'A sync must not emit its delete hook for a chunk owned by another sync.' );
		$this->assertSame( 0, $right_delete_hits, 'Deleting through the wrong sync must not consume the owning sync delete event.' );
		$this->assertSame( ProcessStatus::SCHEDULED, $stored_chunk->get_status(), 'A sync must not mutate chunk state for another sync.' );
	}

	public function test_sequential_sync_waits_for_finish_instead_of_per_action_complete(): void {
		$first_job  = $this->create_sync_double( 'asp_test_sequence_first_job' );
		$second_job = $this->create_sync_double( 'asp_test_sequence_second_job' );
		$sequence   = $this->create_sequence_double( 'asp_test_sequence', array( $first_job, $second_job ) );

		$sequence->boot_queue();
		$sequence->callback();

		$this->assertSame( $first_job->get_sync_name(), $sequence->get_current_job_name(), 'The first job should start executing first.' );

		do_action( $first_job->get_sync_name() . '/complete', new \stdClass(), 123 );

		$this->assertSame(
			$first_job->get_sync_name(),
			$sequence->get_current_job_name(),
			'Sequential sync must not advance to the next job on a per-action complete event.'
		);

		do_action( $first_job->get_sync_name() . '/finish', 'test_group' );

		$this->assertSame( $second_job->get_sync_name(), $sequence->get_current_job_name(), 'Sequential sync should advance once the current job finishes.' );
	}

	public function test_parallel_workers_guard_finish_and_sync_data_writes(): void {
		$sync = new Parallel_Finish_Test_Sync();
		$sync->schedule();

		$group = $sync->get_sync_group_name();
		$action_ids = Action_Scheduler_Test_Helper::get_pending_action_ids(
			Parallel_Finish_Test_Sync::SYNC_NAME . '/process_chunk',
			$group
		);

		$this->assertCount( 2, $action_ids, 'The test sync should schedule two chunks.' );

		$processed = Action_Scheduler_Test_Helper::run_actions_in_parallel( $action_ids );

		$this->assertSame( 2, $processed, 'Both queued chunks should be processed.' );
		$this->assertGreaterThanOrEqual(
			2,
			Action_Scheduler_Test_Helper::get_last_parallel_active_worker_count(),
			'Expected more than one parallel Action Scheduler worker to process actions.'
		);
		$parallel_writes = $sync->get_parallel_writes();
		sort( $parallel_writes );

		$this->assertSame( array( 'first', 'second' ), $parallel_writes, 'Concurrent sync-data writes should preserve both worker updates.' );
		$this->assertCount(
			2,
			array_unique( $sync->get_finish_attempts() ),
			'Both workers should attempt to mark the sync as finished.'
		);
		$this->assertSame(
			array( $group ),
			$sync->get_finish_groups(),
			'The finish hook should still fire exactly once for the group.'
		);

		$this->assert_sync_finished( $group );
	}

	/**
	 * @dataProvider terminal_action_provider
	 */
	public function test_terminal_action_handlers_update_chunk_state_and_fire_hooks(
		string $hook_suffix,
		string $handler,
		ProcessStatus $expected_status
	): void {
		$sync       = $this->create_sync_double( 'asp_test_terminal_handlers_' . $hook_suffix );
		$group      = $sync->get_sync_group_name();
		$chunk      = new Chunk();
		$hook_calls = 0;

		$chunk->set_group( $group );
		$chunk->set_status( ProcessStatus::SCHEDULED );
		$chunk->set_data( array( 'row' => $hook_suffix ) );
		$chunk_id  = $chunk->save();
		$action_id = as_enqueue_async_action( $sync->get_sync_name() . '/process_chunk', array( 'chunk_id' => $chunk_id ), $group );

		$chunk->set_action_id( $action_id );
		$chunk->save();

		add_action(
			$sync->get_sync_name() . '/' . $hook_suffix,
			static function () use ( &$hook_calls ): void {
				++$hook_calls;
			},
			10,
			3
		);

		if ( 'handle_exception' === $handler ) {
			$sync->{$handler}( $action_id, new Exception( 'Synthetic test failure' ) );
		} else {
			$sync->{$handler}( $action_id );
		}

		$stored_chunk = new Chunk( $chunk_id );

		$this->assertSame( 1, $hook_calls, 'The lifecycle hook should fire exactly once for the terminal action.' );
		$this->assertEquals( $expected_status, $stored_chunk->get_status(), 'The chunk status should reflect the terminal action outcome.' );
		$this->assertNotNull( $stored_chunk->get_end(), 'The terminal action should stamp the chunk end time.' );
	}

	public static function terminal_action_provider(): array {
		return array(
			'timeout' => array( 'timeout', 'handle_timeout', ProcessStatus::TIMED_OUT ),
			'cancel'  => array( 'cancel', 'handle_cancel', ProcessStatus::CANCELLED ),
			'fail'    => array( 'fail', 'handle_exception', ProcessStatus::FAILED ),
		);
	}

	/**
	 * Creates a minimal sync instance for exercising the shared lifecycle code.
	 */
	private function create_sync_double( string $sync_name ): Sync {
		return new Action_Tracking_Test_Sync( $sync_name );
	}

	/**
	 * Creates a minimal sequential sync for exercising queue progression logic.
	 *
	 * @param string $sync_name Sequence hook name.
	 * @param Sync[] $jobs Child syncs in execution order.
	 */
	private function create_sequence_double( string $sync_name, array $jobs ): Sequential_Sync {
		return new Action_Tracking_Test_Sequential_Sync( $sync_name, $jobs );
	}
}

class Action_Tracking_Test_Sync extends Sync {

	private string $sync_name;

	public function __construct( string $sync_name ) {
		$this->sync_name = $sync_name;
		parent::__construct();
	}

	public function get_sync_name(): string {
		return $this->sync_name;
	}

	public function schedule(): void {
	}

	public function process_chunk( int $chunk_id ): void {
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
	}
}

class Action_Tracking_Test_Sequential_Sync extends Sequential_Sync {

	private string $sync_name;

	/**
	 * @var Sync[]
	 */
	private array $jobs;

	public function __construct( string $sync_name, array $jobs ) {
		$this->sync_name = $sync_name;
		$this->jobs      = $jobs;
		parent::__construct();
	}

	public function get_sync_name(): string {
		return $this->sync_name;
	}

	public function schedule(): void {
	}

	public function process_chunk( int $chunk_id ): void {
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
	}

	protected function get_jobs(): array {
		return $this->jobs;
	}

	public function boot_queue(): void {
		$this->queue_init();
	}

	public function get_current_job_name(): ?string {
		$current_sync = $this->get_shared_sync_data_store()->get( 'current_sync' );

		if ( ! $current_sync instanceof Sync ) {
			return null;
		}

		return $current_sync->get_sync_name();
	}
}
