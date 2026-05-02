<?php
/**
 * Bootstraps WordPress application tests for the AS Processor demo plugin.
 *
 * Runs inside the wp-env tests-cli container, which exposes WP_TESTS_DIR
 * pointing at /tmp/wordpress-tests-lib. PHPUnit + polyfills are installed
 * in tests/vendor/ via tests/composer.json.
 *
 * @package AS_Processor_Demo
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $tests_dir || '' === $tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run tests via: npm run test:e2e\n" );
	exit( 1 );
}

// Load PHPUnit + Yoast polyfills from the test-only composer install.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Generate the Excel fixture once per test run if it's missing. Keeping
// this inside the bootstrap avoids relying on host-side shell scripts.
$fixture_path = dirname( __DIR__ ) . '/data/products.xlsx';

if ( ! file_exists( $fixture_path ) ) {
	require dirname( __DIR__ ) . '/bin/generate-excel.php';
}

// Required WordPress test helpers (provides tests_add_filter()).
require_once $tests_dir . '/includes/functions.php';

// Load the demo plugin before WordPress finishes loading. This also pulls
// in the library autoloader + Action Scheduler through the demo plugin's
// composer dependencies.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/as-processor-demo.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

// Shared test-support helpers — load after WordPress so they can use its API.
require_once __DIR__ . '/support/Action_Scheduler_Test_Helper.php';
require_once __DIR__ . '/support/Parallel_Finish_Test_Sync.php';
require_once __DIR__ . '/support/E2E_Test_Case.php';
