<?php
/**
 * Generates the products.xlsx fixture on demand.
 *
 * Called automatically by the test bootstrap and can be invoked by hand:
 *
 *     wp-env run cli bash -lc "cd /var/www/html/wp-content/plugins/as-processor-demo && php bin/generate-excel.php"
 *
 * Uses PhpSpreadsheet from the demo plugin's composer install (which pulls
 * the library, which brings PhpSpreadsheet with it).
 *
 * @package AS_Processor_Demo
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$data_dir = dirname( __DIR__ ) . '/data/';

if ( ! is_dir( $data_dir ) && ! mkdir( $data_dir, 0755, true ) && ! is_dir( $data_dir ) ) {
	fwrite( STDERR, sprintf( "Could not create fixture directory: %s\n", $data_dir ) );
	exit( 1 );
}

$rows = array(
	array( 'SKU', 'Product Name', 'Price', 'Category', 'Stock' ),
	array( 'XLS-001', 'Wireless Keyboard', 79.99, 'Electronics', 50 ),
	array( 'XLS-002', '27-inch Monitor', 349.99, 'Electronics', 20 ),
	array( 'XLS-003', 'Adjustable Standing Desk', 599.00, 'Furniture', 8 ),
	array( 'XLS-004', 'Noise Cancelling Headphones', 249.99, 'Electronics', 35 ),
	array( 'XLS-005', 'Smartphone Stand', 19.99, 'Accessories', 120 ),
	array( 'XLS-006', 'HDMI Cable 6ft', 12.99, 'Accessories', 200 ),
	array( 'XLS-007', 'Webcam Cover', 7.99, 'Accessories', 500 ),
	array( 'XLS-008', 'USB Flash Drive 128GB', 24.99, 'Electronics', 75 ),
	array( 'XLS-009', 'Mesh Office Chair', 399.00, 'Furniture', 15 ),
	array( 'XLS-010', 'Desk Organizer', 34.99, 'Accessories', 60 ),
);

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle( 'Products' );
$sheet->fromArray( $rows, null, 'A1' );

$writer = new Xlsx( $spreadsheet );
$writer->save( $data_dir . 'products.xlsx' );

echo "Excel fixture written to {$data_dir}products.xlsx\n";
