<?php
/**
 * Covers the JSON import workflow end-to-end.
 *
 * @package AS_Processor_Demo\Tests
 */

namespace AS_Processor_Demo\Tests\Integration;

use AS_Processor_Demo\Lead_JSON_Import;
use AS_Processor_Demo\Tests\Support\E2E_Test_Case;

/**
 * @group json
 */
class JSON_Import_Test extends E2E_Test_Case {

	public function test_fixture_contains_leads_array(): void {
		$payload = json_decode( (string) file_get_contents( ASP_DEMO_DATA_DIR . 'leads.json' ), true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'leads', $payload );
		$this->assertCount( 12, $payload['leads'] );
	}

	public function test_json_import_runs_through_real_queue(): void {
		$root_action_id = $this->schedule_import( Lead_JSON_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Lead_JSON_Import::SYNC_NAME,
			$root_action_id
		);

		// 12 leads / chunk_size 5 = 3 chunks (5 + 5 + 2).
		$this->assertCount(
			3,
			$this->get_action_ids( Lead_JSON_Import::SYNC_NAME . '/process_chunk', 'pending', $group )
		);

		$this->run_sync_to_completion( Lead_JSON_Import::SYNC_NAME, $group );
		$this->assert_sync_finished( $group );
		$this->assertSame( 12, $this->get_post_count( 'asp_lead' ) );
	}

	public function test_json_import_persists_expected_lead_meta(): void {
		$root_action_id = $this->schedule_import( Lead_JSON_Import::SYNC_NAME );
		$group          = $this->run_root_action_and_get_group(
			Lead_JSON_Import::SYNC_NAME,
			$root_action_id
		);
		$this->run_sync_to_completion( Lead_JSON_Import::SYNC_NAME, $group );

		$leads = get_posts(
			array(
				'post_type'      => 'asp_lead',
				'meta_key'       => 'email',
				'meta_value'     => 'john.smith@example.com',
				'posts_per_page' => 1,
			)
		);

		$this->assertNotEmpty( $leads );

		$lead_id = (int) $leads[0]->ID;

		$this->assertSame( 'John Smith', get_the_title( $lead_id ) );
		$this->assertSame( 'TechCorp Inc', get_post_meta( $lead_id, 'company', true ) );
		$this->assertSame( 85, (int) get_post_meta( $lead_id, 'lead_score', true ) );
	}
}
