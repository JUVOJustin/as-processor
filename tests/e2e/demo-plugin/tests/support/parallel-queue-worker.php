<?php
/**
 * Standalone worker that executes a single Action Scheduler action.
 *
 * PHPUnit launches several of these in parallel, synchronised by a filesystem
 * barrier, so concurrency-sensitive behaviour can be exercised with real,
 * separate PHP processes and database connections. The motivating case is two
 * chunk actions of the same sync completing at the same time and racing to fire
 * the sync's finish hook: only one must win.
 *
 * @package AS_Processor_Demo\Tests
 */

$options = getopt(
	'',
	array(
		'result:',
		'start-file:',
		'action-id:',
		'barrier-dir::',
		'barrier-workers::',
		'context::',
	)
);

$result_file = isset( $options['result'] ) ? (string) $options['result'] : '';
$start_file  = isset( $options['start-file'] ) ? (string) $options['start-file'] : '';
$action_id   = isset( $options['action-id'] ) ? (int) $options['action-id'] : 0;

if ( '' === $result_file || '' === $start_file || $action_id <= 0 ) {
	fwrite( STDERR, "Missing --result, --start-file or --action-id.\n" );
	exit( 1 );
}

require dirname( __DIR__, 5 ) . '/wp-load.php';

require_once dirname( __DIR__, 2 ) . '/as-processor-demo.php';
require_once __DIR__ . '/Parallel_Finish_Test_Sync.php';

$plugin = AS_Processor_Demo\Plugin::get_instance();
$plugin->register_post_types();
$plugin->register_imports();
new AS_Processor_Demo\Tests\Support\Parallel_Finish_Test_Sync();

$context         = (string) ( $options['context'] ?? 'Parallel PHPUnit Worker' );
$barrier_dir     = (string) ( $options['barrier-dir'] ?? '' );
$barrier_workers = max( 1, (int) ( $options['barrier-workers'] ?? 1 ) );

// Wait until the parent releases every worker at once.
$deadline = microtime( true ) + 20;

while ( ! file_exists( $start_file ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}

if ( ! file_exists( $start_file ) ) {
	fwrite( STDERR, "Timed out waiting for start file.\n" );
	exit( 1 );
}

// Cross-process barrier: do not execute until every sibling worker has reached
// this point, so the actions run as concurrently as the OS allows.
if ( '' !== $barrier_dir ) {
	$marker = trailingslashit( $barrier_dir ) . md5( (string) getmypid() . ':' . (string) $action_id ) . '.started';
	touch( $marker );

	$deadline = microtime( true ) + 10;
	$started  = array();

	do {
		$started = glob( trailingslashit( $barrier_dir ) . '*.started' );

		if ( is_array( $started ) && count( $started ) >= $barrier_workers ) {
			break;
		}

		usleep( 10000 );
	} while ( microtime( true ) < $deadline );

	if ( ! is_array( $started ) || count( $started ) < $barrier_workers ) {
		fwrite( STDERR, "Timed out waiting for parallel worker barrier.\n" );
		exit( 1 );
	}
}

$error = '';

try {
	ActionScheduler_QueueRunner::instance()->process_action( $action_id, $context );
} catch ( \Throwable $e ) {
	// process_action swallows the action's own exceptions; reaching here means
	// the runner itself failed. Surface it as a worker failure with diagnostics.
	fwrite( STDERR, 'process_action failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

file_put_contents(
	$result_file,
	wp_json_encode( array( 'processed' => 1 ) )
);

exit( 0 );
