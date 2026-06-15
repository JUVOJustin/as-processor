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

		self::$last_parallel_worker_counts = array();

		$tmp_dir = trailingslashit( sys_get_temp_dir() ) . 'asp-parallel-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			throw new RuntimeException( sprintf( 'Could not create worker temp directory: %s', $tmp_dir ) );
		}

		try {
			$start_file  = trailingslashit( $tmp_dir ) . 'start';
			$barrier_dir = trailingslashit( $tmp_dir ) . 'barrier';
			$processes   = array();
			$action_ids  = array_values( array_map( 'intval', $action_ids ) );

			if ( ! wp_mkdir_p( $barrier_dir ) ) {
				throw new RuntimeException( sprintf( 'Could not create worker barrier directory: %s', $barrier_dir ) );
			}

			foreach ( $action_ids as $worker => $action_id ) {
				$processes[] = self::start_parallel_worker(
					$worker,
					$tmp_dir,
					$start_file,
					$action_id,
					$barrier_dir,
					count( $action_ids )
				);
			}

			touch( $start_file );

			foreach ( $processes as $process ) {
				self::$last_parallel_worker_counts[] = self::finish_parallel_worker( $process );
			}
		} finally {
			// Always remove the temp dir, even if a worker times out or fails.
			self::delete_directory( $tmp_dir );
		}

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
	 * Start one worker process that executes a single Action Scheduler action.
	 *
	 * @param int    $worker        Worker index, used to name result/log files.
	 * @param string $tmp_dir       Shared temp directory for this run.
	 * @param string $start_file    File whose creation releases all workers at once.
	 * @param int    $action_id     Action Scheduler action ID to execute.
	 * @param string $barrier_dir   Directory used for the cross-process start barrier.
	 * @param int    $barrier_count Number of workers that must reach the barrier.
	 * @return array<string,mixed>
	 */
	private static function start_parallel_worker(
		int $worker,
		string $tmp_dir,
		string $start_file,
		int $action_id,
		string $barrier_dir,
		int $barrier_count
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
			'--action-id=' . $action_id,
			'--barrier-dir=' . $barrier_dir,
			'--barrier-workers=' . $barrier_count,
			'--context=Parallel PHPUnit Worker ' . $worker,
		);

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
	 * @return int Number of actions the worker processed.
	 */
	private static function finish_parallel_worker( array $worker ): int {
		$process  = $worker['process'];
		$deadline = microtime( true ) + 60;
		$running  = true;

		// proc_get_status() reports a real exit code only on the FIRST call after
		// the process exits, so capture it the moment we observe termination
		// rather than calling proc_get_status() again afterwards (which returns -1).
		$exit_code = -1;

		do {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				$running   = false;
				$exit_code = isset( $status['exitcode'] ) ? (int) $status['exitcode'] : -1;
				break;
			}

			usleep( 20000 );
		} while ( microtime( true ) < $deadline );

		if ( $running ) {
			proc_terminate( $process );
			proc_close( $process );
			throw new RuntimeException( 'Parallel queue worker timed out.' );
		}

		$close_code = proc_close( $process );

		if ( -1 === $exit_code ) {
			$exit_code = $close_code;
		}

		$result = is_readable( $worker['result'] ) ? json_decode( (string) file_get_contents( $worker['result'] ), true ) : null;

		// A positive exit code is a definite failure. -1 (undeterminable) is
		// tolerated only when the worker still wrote a valid result file.
		if ( ! is_array( $result ) || ! isset( $result['processed'] ) || $exit_code > 0 ) {
			throw new RuntimeException(
				sprintf(
					"Parallel queue worker failed with exit code %d.\nSTDOUT:\n%s\nSTDERR:\n%s",
					$exit_code,
					is_readable( $worker['stdout'] ) ? file_get_contents( $worker['stdout'] ) : '',
					is_readable( $worker['stderr'] ) ? file_get_contents( $worker['stderr'] ) : ''
				)
			);
		}

		return (int) $result['processed'];
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
