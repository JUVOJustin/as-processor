<?php
/**
 * Mock REST API exposing a paginated product list for the API import.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo\Rest;

use WP_REST_Request;

final class Mock_Products_Controller {

	private const NAMESPACE   = 'as-processor-demo/v1';
	private const TOTAL_ITEMS = 35;
	private const PER_PAGE    = 10;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<page>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page' => array(
						'validate_callback' => static fn ( $param ) => is_numeric( $param ) && (int) $param >= 1,
					),
				),
			)
		);
	}

	/**
	 * Return a deterministic page of mock products so tests can assert on
	 * specific field values.
	 */
	public function get_products( WP_REST_Request $request ): array {
		$page    = (int) $request->get_param( 'page' );
		$offset  = ( $page - 1 ) * self::PER_PAGE;
		$total   = self::TOTAL_ITEMS;
		$records = array();

		for ( $i = $offset + 1; $i <= min( $offset + self::PER_PAGE, $total ); $i++ ) {
			$records[] = array(
				'id'       => $i,
				'name'     => sprintf( 'API Product %d', $i ),
				'sku'      => sprintf( 'API-%04d', $i ),
				'price'    => round( 10 + ( $i * 1.25 ), 2 ),
				'category' => array( 'Electronics', 'Clothing', 'Books' )[ ( $i - 1 ) % 3 ],
				'stock'    => $i * 2,
			);
		}

		$total_pages = (int) ceil( $total / self::PER_PAGE );

		return array(
			'data'       => $records,
			'pagination' => array(
				'current_page' => $page,
				'total_pages'  => $total_pages,
				'total_items'  => $total,
				'per_page'     => self::PER_PAGE,
				'has_next'     => $page < $total_pages,
			),
		);
	}
}
