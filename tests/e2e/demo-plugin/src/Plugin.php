<?php
/**
 * Main plugin controller for the AS Processor demo plugin.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use AS_Processor_Demo\Rest\Mock_Products_Controller;

/**
 * Registers the demo CPTs, the mock REST endpoint, and the library import
 * instances. Imports are kept on the Plugin instance so their Action
 * Scheduler hooks stay registered for the whole request.
 */
final class Plugin {

	private static ?self $instance = null;

	/**
	 * Import instances kept alive so their hooks remain registered.
	 *
	 * @var array<int,object>
	 */
	private array $imports = array();

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_imports' ), 20 );

		( new Mock_Products_Controller() )->register();
	}

	/**
	 * Register the three demo CPTs that act as import targets.
	 */
	public function register_post_types(): void {
		register_post_type(
			'asp_product',
			array(
				'labels'       => array(
					'name'          => 'Demo Products',
					'singular_name' => 'Demo Product',
				),
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);

		register_post_type(
			'asp_lead',
			array(
				'labels'       => array(
					'name'          => 'Demo Leads',
					'singular_name' => 'Demo Lead',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);

		register_post_type(
			'asp_api_item',
			array(
				'labels'       => array(
					'name'          => 'Demo API Items',
					'singular_name' => 'Demo API Item',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Instantiate the demo imports so their hooks get registered.
	 */
	public function register_imports(): void {
		if ( array() !== $this->imports ) {
			return;
		}

		$this->imports = array(
			new Product_CSV_Import(),
			new Lead_JSON_Import(),
			new Product_Excel_Import(),
			new Product_API_Import(),
		);
	}
}
