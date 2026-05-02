<?php
/**
 * Focused Action Scheduler helpers for application tests.
 *
 * Uses the real ActionScheduler_QueueRunner so tests exercise the same
 * dispatch path as production (argument serialization, hook resolution,
 * status transitions).
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Support;

use ActionScheduler_QueueRunner;
use ActionScheduler_Store;
use RuntimeException;

final class Action_Scheduler_Test_Helper {

	/**
	 * Processed counts from the last parallel queue drain.
	 *
	 * @var int[]
	 */
	private static array $last_parallel_worker_counts = array();

	/**
	 * Raw worker results from the last parallel queue drain.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $last_parallel_worker_results = array();

	/**
	 * Execute a single queued action through the real queue runner.
	 *
	 * @param int $action_id Action Scheduler action ID.
	 */
	public static function run_action( int $action_id ): void {
		ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'PHPUnit' );
	}

	/**
	 * Return pending action IDs for an exact hook (and optional group).
	 *
	 * @param string      $hook  Action hook.
	 * @param string|null $group Optional action group.
	 * @return int[]
	 */
	public static function get_pending_action_ids( string $hook, ?string $group = null ): array {
		$query = array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		);

		if ( null !== $group ) {
			$query['group'] = $group;
		}

		return array_map( 'intval', as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * Run every pending action matching the given hook and optional group.
	 *
	 * @param string      $hook  Action hook.
	 * @param string|null $group Optional action group.
	 * @return int Number of actions processed.
	 */
	public static function run_all_pending( string $hook, ?string $group = null ): int {
		$action_ids = self::get_pending_action_ids( $hook, $group );

		foreach ( $action_ids as $action_id ) {
			self::run_action( $action_id );
		}

		return count( $action_ids );
	}

	/**
	 * Process a set of hooks repeatedly until no pending actions remain.
	 *
	 * Action Scheduler jobs can spawn more jobs (dispatcher → chunks, API
	 * fetch → next fetch). Looping until idle keeps the assertion surface
	 * on the final state rather than a specific scheduling order.
	 *
	 * @param string[]    $hooks          Hooks to drain.
	 * @param string|null $group          Optional action group.
	 * @param int         $max_iterations Safety cap to avoid infinite loops.
	 * @return int Total number of actions processed.
	 */
	public static function run_until_idle( array $hooks, ?string $group = null, int $max_iterations = 50 ): int {
		$total      = 0;
		$iterations = 0;

		do {
			$processed_this_round = 0;

			foreach ( $hooks as $hook ) {
				$processed_this_round += self::run_all_pending( $hook, $group );
			}

			$total += $processed_this_round;
			++$iterations;
		} while ( 0 !== $processed_this_round && $iterations < $max_iterations );

		return $total;
	}

	/**
	 * Process pending actions with multiple real PHP queue-runner processes.
	 *
	 * This exercises Action Scheduler's claim-based concurrent batch handling
	 * instead of directly executing action IDs in one PHP process.
	 *
	 * @param string[]    $hooks          Hooks to drain.
	 * @param string|null $group          Optional action group.
	 * @param int         $workers        Number of queue-runner processes.
	 * @param int         $max_iterations Safety cap to avoid infinite loops.
	 * @return int Total number of actions processed.
	 */
	public static function run_until_idle_parallel(
		array $hooks,
		?string $group = null,
		int $workers = 2,
		int $max_iterations = 20
	): int {
		if ( ! function_exists( 'proc_open' ) ) {
			throw new RuntimeException( 'Parallel Action Scheduler tests require proc_open().' );
		}

		self::make_parent_transaction_visible_to_workers();

		self::$last_parallel_worker_counts = array();

		$total      = 0;
		$iterations = 0;

		do {
			$pending_before = self::count_pending_actions( $hooks, $group );

			if ( 0 === $pending_before ) {
				break;
			}

			$worker_counts = self::run_parallel_worker_wave( $workers );
			$processed     = array_sum( $worker_counts );

			self::$last_parallel_worker_counts = array_merge(
				self::$last_parallel_worker_counts,
				$worker_counts
			);

			if ( 0 === $processed && $pending_before > 0 ) {
				throw new RuntimeException(
					sprintf(
						"Parallel queue workers did not process pending actions.\nWorker results: %s",
						wp_json_encode( self::$last_parallel_worker_results )
					)
				);
			}

			$total += $processed;
			++$iterations;
		} while ( $iterations < $max_iterations );

		if ( self::count_pending_actions( $hooks, $group ) > 0 ) {
			throw new RuntimeException( 'Parallel queue workers reached the maximum iteration limit.' );
		}

		return $total;
	}

	/**
	 * Process specific Action Scheduler actions in separate PHP worker processes.
	 *
	 * This is used for deterministic concurrency tests where each worker must
	 * execute one known action instead of racing through Action Scheduler claims.
	 *
	 * @param int[] $action_ids Action IDs to process in parallel.
	 * @return int Total number of actions processed.
	 */
	public static function run_actions_in_parallel( array $action_ids ): int {
		if ( ! function_exists( 'proc_open' ) ) {
			throw new RuntimeException( 'Parallel Action Scheduler tests require proc_open().' );
		}

		self::make_parent_transaction_visible_to_workers();

		self::$last_parallel_worker_counts  = array();
		self::$last_parallel_worker_results = array();

		$tmp_dir = trailingslashit( sys_get_temp_dir() ) . 'asp-parallel-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			throw new RuntimeException( sprintf( 'Could not create worker temp directory: %s', $tmp_dir ) );
		}

		$start_file  = trailingslashit( $tmp_dir ) . 'start';
		$barrier_dir = trailingslashit( $tmp_dir ) . 'barrier';
		$processes   = array();
		$action_ids   = array_values( array_map( 'intval', $action_ids ) );

		if ( ! wp_mkdir_p( $barrier_dir ) ) {
			throw new RuntimeException( sprintf( 'Could not create worker barrier directory: %s', $barrier_dir ) );
		}

		foreach ( $action_ids as $worker => $action_id ) {
			$processes[] = self::start_parallel_worker(
				$worker,
				$tmp_dir,
				$start_file,
				count( $action_ids ),
				$action_id,
				$barrier_dir
			);
		}

		touch( $start_file );

		foreach ( $processes as $process ) {
			$result = self::finish_parallel_worker( $process );
			self::$last_parallel_worker_results[] = $result;
			self::$last_parallel_worker_counts[]  = (int) $result['processed'];
		}

		self::delete_directory( $tmp_dir );

		return array_sum( self::$last_parallel_worker_counts );
	}

	/**
	 * Return how many workers processed at least one action in the last drain.
	 */
	public static function get_last_parallel_active_worker_count(): int {
		return count(
			array_filter(
				self::$last_parallel_worker_counts,
				static fn ( int $processed ): bool => $processed > 0
			)
		);
	}

	/**
	 * Return lifecycle hook counts captured by external workers during the last drain.
	 *
	 * @return array{start:int,complete:int,finish:int}
	 */
	public static function get_last_parallel_lifecycle_counts(): array {
		$counts = array(
			'start'    => 0,
			'complete' => 0,
			'finish'   => 0,
		);

		foreach ( self::$last_parallel_worker_results as $result ) {
			if ( empty( $result['lifecycle'] ) || ! is_array( $result['lifecycle'] ) ) {
				continue;
			}

			foreach ( $counts as $event => $count ) {
				$counts[ $event ] += (int) ( $result['lifecycle'][ $event ] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * Count pending actions for the given hooks and optional group.
	 *
	 * @param string[]    $hooks Hooks to count.
	 * @param string|null $group Optional action group.
	 */
	private static function count_pending_actions( array $hooks, ?string $group = null ): int {
		$total = 0;

		foreach ( $hooks as $hook ) {
			$total += count( self::get_pending_action_ids( $hook, $group ) );
		}

		return $total;
	}

	/**
	 * Commit the WordPress test transaction so external worker processes can see queued actions.
	 */
	private static function make_parent_transaction_visible_to_workers(): void {
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			\WP_UnitTestCase::commit_transaction();
		}

		global $wpdb;

		if ( isset( $wpdb ) ) {
			$wpdb->query( 'SET autocommit = 1;' );
		}
	}

	/**
	 * Start a group of queue-runner workers and wait for all of them to finish.
	 *
	 * @param int $workers Number of workers to start.
	 * @return int[] Processed action count per worker.
	 */
	private static function run_parallel_worker_wave( int $workers ): array {
		$tmp_dir = trailingslashit( sys_get_temp_dir() ) . 'asp-parallel-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			throw new RuntimeException( sprintf( 'Could not create worker temp directory: %s', $tmp_dir ) );
		}

		$start_file = trailingslashit( $tmp_dir ) . 'start';
		$processes  = array();

		for ( $i = 0; $i < $workers; $i++ ) {
			$processes[] = self::start_parallel_worker( $i, $tmp_dir, $start_file, $workers );
		}

		touch( $start_file );

		$counts = array();
		self::$last_parallel_worker_results = array();

		foreach ( $processes as $process ) {
			$result = self::finish_parallel_worker( $process );
			self::$last_parallel_worker_results[] = $result;
			$counts[] = (int) $result['processed'];
		}

		self::delete_directory( $tmp_dir );

		return $counts;
	}

	/**
	 * Start one worker process.
	 *
	 * @return array<string,mixed>
	 */
	private static function start_parallel_worker(
		int $worker,
		string $tmp_dir,
		string $start_file,
		int $workers,
		?int $action_id = null,
		?string $barrier_dir = null
	): array {
		$script      = __DIR__ . '/parallel-queue-worker.php';
		$result_file = trailingslashit( $tmp_dir ) . "worker-{$worker}.json";
		$stdout_file = trailingslashit( $tmp_dir ) . "worker-{$worker}.out";
		$stderr_file = trailingslashit( $tmp_dir ) . "worker-{$worker}.err";

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $stdout_file, 'w' ),
			2 => array( 'file', $stderr_file, 'w' ),
		);

		$command = array(
			PHP_BINARY,
			$script,
			'--result=' . $result_file,
			'--start-file=' . $start_file,
			'--batch-size=1',
			'--concurrent-batches=' . max( $workers + 1, 3 ),
			'--delay-us=25000',
			'--context=Parallel PHPUnit Worker ' . $worker,
		);

		if ( null !== $action_id ) {
			$command[] = '--action-id=' . $action_id;
			$command[] = '--barrier-dir=' . (string) $barrier_dir;
			$command[] = '--barrier-workers=' . $workers;
		}

		$process = proc_open( $command, $descriptors, $pipes, dirname( __DIR__ ) );

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start parallel queue worker.' );
		}

		if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
			fclose( $pipes[0] );
		}

		return array(
			'process' => $process,
			'result'  => $result_file,
			'stdout'  => $stdout_file,
			'stderr'  => $stderr_file,
		);
	}

	/**
	 * Wait for a worker and return the number of processed actions.
	 *
	 * @param array<string,mixed> $worker Worker process metadata.
	 */
	private static function finish_parallel_worker( array $worker ): array {
		$process  = $worker['process'];
		$deadline = microtime( true ) + 60;

		do {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				break;
			}

			usleep( 20000 );
		} while ( microtime( true ) < $deadline );

		$status    = proc_get_status( $process );
		$exit_code = isset( $status['exitcode'] ) ? (int) $status['exitcode'] : -1;

		if ( $status['running'] ) {
			proc_terminate( $process );
			proc_close( $process );
			throw new RuntimeException( 'Parallel queue worker timed out.' );
		}

		$close_code = proc_close( $process );

		if ( -1 === $exit_code ) {
			$exit_code = $close_code;
		}

		$result = is_readable( $worker['result'] ) ? json_decode( (string) file_get_contents( $worker['result'] ), true ) : null;

		if ( ! is_array( $result ) || ! isset( $result['processed'] ) ) {
			throw new RuntimeException(
				sprintf(
					"Parallel queue worker failed with exit code %d.\nSTDOUT:\n%s\nSTDERR:\n%s",
					$exit_code,
					is_readable( $worker['stdout'] ) ? file_get_contents( $worker['stdout'] ) : '',
					is_readable( $worker['stderr'] ) ? file_get_contents( $worker['stderr'] ) : ''
				)
			);
		}

		if ( 0 !== $exit_code && -1 !== $exit_code ) {
			throw new RuntimeException(
				sprintf(
					"Parallel queue worker failed with exit code %d.\nSTDOUT:\n%s\nSTDERR:\n%s",
					$exit_code,
					is_readable( $worker['stdout'] ) ? file_get_contents( $worker['stdout'] ) : '',
					is_readable( $worker['stderr'] ) ? file_get_contents( $worker['stderr'] ) : ''
				)
			);
		}

		return $result;
	}

	/**
	 * Remove a temporary worker directory.
	 */
	private static function delete_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$file = trailingslashit( $path ) . $entry;

			if ( is_dir( $file ) ) {
				self::delete_directory( $file );
			} elseif ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		rmdir( $path );
	}
}
