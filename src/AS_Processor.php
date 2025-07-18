<?php
/**
 * Main library initialization class.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor;

use juvo\AS_Processor\REST\Sync_REST_Controller;

/**
 * Class AS_Processor
 *
 * Main library initialization class that sets up REST API and other features
 * for processing large datasets with Action Scheduler.
 */
class AS_Processor {

	/**
	 * Initialize the library.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register REST API routes.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		$controller = new Sync_REST_Controller();
		$controller->register_routes();
	}
}
