<?php
/**
 * Standalone worker used by PHPUnit to run Action Scheduler queues in parallel.
 *
 * @package AS_Processor_Demo\Tests
 */

$options = getopt(
	'',
	array(
		'result:',
		'start-file:',
		'batch-size::',
		'concurrent-batches::',
		'delay-us::',
		'context::',
		'action-id::',
		'barrier-dir::',
		'barrier-workers::',
	)
);

$result_file = isset( $options['result'] ) ? (string) $options['result'] : '';
$start_file  = isset( $options['start-file'] ) ? (string) $options['start-file'] : '';

if ( '' === $result_file || '' === $start_file ) {
	fwrite( STDERR, "Missing --result or --start-file.\n" );
	exit( 1 );
}

require dirname( __DIR__, 5 ) . '/wp-load.php';

require_once dirname( __DIR__, 2 ) . '/as-processor-demo.php';
require_once __DIR__ . '/Parallel_Finish_Test_Sync.php';

$plugin = AS_Processor_Demo\Plugin::get_instance();
$plugin->register_post_types();
$plugin->register_imports();
new AS_Processor_Demo\Tests\Support\Parallel_Finish_Test_Sync();

if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}

$lifecycle_counts = array(
	'start'    => 0,
	'complete' => 0,
	'finish'   => 0,
);

foreach (
	array(
		AS_Processor_Demo\Product_CSV_Import::SYNC_NAME,
		AS_Processor_Demo\Lead_JSON_Import::SYNC_NAME,
		AS_Processor_Demo\Product_Excel_Import::SYNC_NAME,
		AS_Processor_Demo\Product_API_Import::SYNC_NAME,
		AS_Processor_Demo\Tests\Support\Parallel_Finish_Test_Sync::SYNC_NAME,
	) as $sync_name
) {
	add_action(
		$sync_name . '/start',
		static function () use ( &$lifecycle_counts ): void {
			++$lifecycle_counts['start'];
		},
		10,
		2
	);

	add_action(
		$sync_name . '/complete',
		static function () use ( &$lifecycle_counts ): void {
			++$lifecycle_counts['complete'];
		},
		10,
		2
	);

	add_action(
		$sync_name . '/finish',
		static function () use ( &$lifecycle_counts ): void {
			++$lifecycle_counts['finish'];
		}
	);
}

$batch_size         = max( 1, (int) ( $options['batch-size'] ?? 1 ) );
$concurrent_batches = max( 2, (int) ( $options['concurrent-batches'] ?? 2 ) );
$delay_us           = max( 0, (int) ( $options['delay-us'] ?? 0 ) );
$context            = (string) ( $options['context'] ?? 'Parallel PHPUnit Worker' );
$action_id          = max( 0, (int) ( $options['action-id'] ?? 0 ) );
$barrier_dir        = (string) ( $options['barrier-dir'] ?? '' );
$barrier_workers    = max( 2, (int) ( $options['barrier-workers'] ?? 2 ) );

add_filter(
	'action_scheduler_queue_runner_batch_size',
	static fn (): int => $batch_size,
	PHP_INT_MAX
);

add_filter(
	'action_scheduler_queue_runner_concurrent_batches',
	static fn (): int => $concurrent_batches,
	PHP_INT_MAX
);

add_filter(
	'action_scheduler_queue_runner_time_limit',
	static fn (): int => 120,
	PHP_INT_MAX
);

if ( $delay_us > 0 ) {
	add_action(
		'action_scheduler_begin_execute',
		static function ( int $action_id ) use ( $delay_us ): void {
			$action = ActionScheduler_Store::instance()->fetch_action( (string) $action_id );

			if ( str_starts_with( $action->get_hook(), 'asp_demo_' ) ) {
				usleep( $delay_us );
			}
		},
		1
	);
}

$deadline = microtime( true ) + 20;

while ( ! file_exists( $start_file ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}

if ( ! file_exists( $start_file ) ) {
	fwrite( STDERR, "Timed out waiting for start file.\n" );
	exit( 1 );
}

$pending_before = count(
	as_get_scheduled_actions(
		array(
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1000,
		),
		'ids'
	)
);

$store_class = get_class( ActionScheduler::store() );

if ( $action_id > 0 ) {
	if ( '' !== $barrier_dir ) {
		$marker = trailingslashit( $barrier_dir ) . md5( (string) getmypid() . ':' . (string) $action_id ) . '.started';
		touch( $marker );

		$deadline        = microtime( true ) + 10;
		$started_workers = array();

		do {
			$started_workers = glob( trailingslashit( $barrier_dir ) . '*.started' );

			if ( is_array( $started_workers ) && count( $started_workers ) >= $barrier_workers ) {
				break;
			}

			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		if ( ! is_array( $started_workers ) || count( $started_workers ) < $barrier_workers ) {
			fwrite( STDERR, "Timed out waiting for parallel worker barrier.\n" );
			exit( 1 );
		}
	}

	ActionScheduler_QueueRunner::instance()->process_action( $action_id, $context );
	$processed = 1;
} else {
	$processed = ActionScheduler_QueueRunner::instance()->run( $context );
}

$pending_after = count(
	as_get_scheduled_actions(
		array(
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1000,
		),
		'ids'
	)
);

file_put_contents(
	$result_file,
	wp_json_encode(
		array(
			'pending_before' => $pending_before,
			'pending_after'  => $pending_after,
			'processed'      => $processed,
			'lifecycle'      => $lifecycle_counts,
			'store'          => $store_class,
		)
	)
);

exit( 0 );
