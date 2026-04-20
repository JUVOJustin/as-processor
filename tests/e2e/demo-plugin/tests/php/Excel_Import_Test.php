<?php
/**
 * Covers the Excel import workflow end-to-end.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Product_Excel_Import;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;

/**
 * @group excel
 */
class Excel_Import_Test extends E2E_Test_Case {

	public function test_fixture_exists_and_is_an_xlsx_file(): void {
		$fixture_path = ASP_DEMO_DATA_DIR . 'products.xlsx';

		$this->assertFileExists( $fixture_path );
		$this->assertFileIsReadable( $fixture_path );

		// XLSX is a ZIP archive — verify the local file header magic bytes.
		$handle = fopen( $fixture_path, 'rb' );
		$header = false === $handle ? '' : (string) fread( $handle, 4 );

		if ( false !== $handle ) {
			fclose( $handle );
		}

		$this->assertSame( "PK\x03\x04", $header );
	}

	public function test_excel_import_runs_through_real_queue(): void {
		$root_action_id = $this->schedule_import( Product_Excel_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Product_Excel_Import::SYNC_NAME,
			$root_action_id
		);

		// 10 rows / chunk_size 5 = exactly 2 chunk jobs.
		$this->assertCount(
			2,
			$this->get_action_ids( Product_Excel_Import::SYNC_NAME . '/process_chunk', 'pending', $group )
		);

		$this->run_sync_to_completion( Product_Excel_Import::SYNC_NAME, $group );
		$this->assert_sync_finished( $group );
		$this->assertSame( 10, $this->get_post_count( 'asp_product' ) );
	}

	public function test_excel_import_persists_expected_product_meta(): void {
		$root_action_id = $this->schedule_import( Product_Excel_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Product_Excel_Import::SYNC_NAME,
			$root_action_id
		);
		$this->run_sync_to_completion( Product_Excel_Import::SYNC_NAME, $group );

		$products = get_posts(
			array(
				'post_type'      => 'asp_product',
				'meta_key'       => 'sku',
				'meta_value'     => 'XLS-001',
				'posts_per_page' => 1,
			)
		);

		$this->assertNotEmpty( $products );

		$product_id = (int) $products[0]->ID;

		$this->assertSame( 'Wireless Keyboard', get_the_title( $product_id ) );
		$this->assertSame( 79.99, (float) get_post_meta( $product_id, 'price', true ) );
		$this->assertSame( 'Electronics', get_post_meta( $product_id, 'category', true ) );
		$this->assertSame( 50, (int) get_post_meta( $product_id, 'stock', true ) );
	}
}
