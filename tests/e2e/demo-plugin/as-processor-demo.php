<?php
/**
 * Plugin Name: AS Processor Demo
 * Description: Demo plugin that exercises every feature of the AS Processor library. Used as the application-test fixture for the library.
 * Version:     1.0.0
 * Author:      juvo
 * License:     GPL-3.0-or-later
 * Requires PHP: 8.1
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use juvo\AS_Processor\AS_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The demo plugin's own Composer install pulls in the library (path repo)
// which in turn registers Action Scheduler when WordPress is ready.
$autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	trigger_error(
		'AS Processor Demo: vendor/autoload.php not found. Run "composer install" inside the demo plugin first.',
		E_USER_ERROR
	);
}

require_once $autoload;
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

AS_Processor::register();

define( 'ASP_DEMO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASP_DEMO_DATA_DIR', ASP_DEMO_PLUGIN_DIR . 'data/' );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::get_instance();
	}
);
