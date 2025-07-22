<?php
/**
 * REST API controller for managing syncs.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\REST;

use InvalidArgumentException;
use juvo\AS_Processor\Stats;
use juvo\AS_Processor\Sync_Registry;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Sync_REST_Controller
 *
 * REST API controller for listing, triggering, and managing sync processes.
 */
class Sync_REST_Controller extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'as-processor/v1';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'syncs';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// List all syncs.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Get detailed sync stats - Register before single sync to avoid route conflict.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'key'        => array(
							'description' => __( 'Sync key identifier.', 'as-processor' ),
							'type'        => 'string',
							'required'    => true,
						),
						'group_name' => array(
							'description' => __( 'Specific group name for stats.', 'as-processor' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Trigger sync - Register before single sync to avoid route conflict.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<key>[\w-]+)/trigger',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'trigger_sync' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'key' => array(
							'description' => __( 'Sync key identifier.', 'as-processor' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get single sync info - Register last as it's the most generic pattern.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<key>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'key' => array(
							'description' => __( 'Sync key identifier.', 'as-processor' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Check permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function permissions_check( $request ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all syncs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$registry = Sync_Registry::instance();
		$items    = array();

		foreach ( $registry->get_all() as $key => $class_name ) {
			$info = $registry->get_sync_info( $key );
			if ( $info ) {
				$sync           = $registry->create_sync( $key );
				$info['status'] = $sync->get_status();
				// Encode the key for REST API output.
				$info['key'] = Sync_Key_Helper::encode( $info['key'] );
				$items[]     = $info;
			}
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Get single sync.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$key      = Sync_Key_Helper::decode( $request->get_param( 'key' ) );
		$registry = Sync_Registry::instance();

		$info = $registry->get_sync_info( $key );
		if ( ! $info ) {
			return new WP_Error(
				'sync_not_found',
				__( 'Sync not found', 'as-processor' ),
				array( 'status' => 404 )
			);
		}

		$sync           = $registry->create_sync( $key );
		$info['status'] = $sync->get_status();
		// Encode the key for REST API output.
		$info['key'] = Sync_Key_Helper::encode( $info['key'] );

		return rest_ensure_response( $info );
	}

	/**
	 * Trigger sync.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function trigger_sync( $request ) {
		$key  = Sync_Key_Helper::decode( $request->get_param( 'key' ) );
		$sync = Sync_Registry::instance()->create_sync( $key );

		if ( ! $sync ) {
			return new WP_Error(
				'sync_not_found',
				__( 'Sync not found', 'as-processor' ),
				array( 'status' => 404 )
			);
		}

		// Check if already running.
		$status = $sync->get_status();
		if ( $status['is_running'] ) {
			return new WP_Error(
				'sync_already_running',
				__( 'Sync is already running', 'as-processor' ),
				array( 'status' => 409 )
			);
		}

		// Trigger the sync.
		$action_id = as_enqueue_async_action(
			$sync->get_sync_name(),
			array(),
			$sync->get_sync_group_name()
		);

		return rest_ensure_response(
			array(
				'action_id' => $action_id,
				'message'   => sprintf(
					/* translators: %s: sync name */
					__( '%s triggered successfully', 'as-processor' ),
					$sync->get_sync_name()
				),
				'sync_key'  => Sync_Key_Helper::encode( $key ),
			)
		);
	}

	/**
	 * Get detailed sync stats.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stats( $request ) {
		$key        = Sync_Key_Helper::decode( $request->get_param( 'key' ) );
		$group_name = $request->get_param( 'group_name' );
		$sync       = Sync_Registry::instance()->create_sync( $key );

		if ( ! $sync ) {
			return new WP_Error(
				'sync_not_found',
				__( 'Sync not found', 'as-processor' ),
				array( 'status' => 404 )
			);
		}

		try {
			$stats = new Stats( $group_name );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error(
				'sync_group_not_found',
				__( 'Sync Group not found', 'as-processor' ),
				array( 'status' => 404 )
			);
		}

		// Get stats data without JSON encoding
		$stats_data = json_decode( $stats->to_json(), true );

		return rest_ensure_response( $stats_data );
	}
}
