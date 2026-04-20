<?php
/**
 * Imports leads from a JSON fixture into the asp_lead CPT.
 *
 * @package AS_Processor_Demo
 */

namespace AS_Processor_Demo;

use AS_Processor_Demo\Support\Demo_Fixture_Manager;
use Generator;
use juvo\AS_Processor\Imports\JSON;

class Lead_JSON_Import extends JSON {

	public const SYNC_NAME = 'asp_demo_lead_json_import';

	public int $chunk_size   = 5;
	public ?string $pointer  = '/leads';

	public function get_sync_name(): string {
		return self::SYNC_NAME;
	}

	public function schedule(): void {
		as_enqueue_async_action( self::SYNC_NAME );
	}

	protected function get_source_path(): string {
		return Demo_Fixture_Manager::create_runtime_copy( 'leads.json' );
	}

	protected function process_chunk_data( Generator $chunk_data ): void {
		foreach ( $chunk_data as $lead ) {
			$this->upsert_lead( (array) $lead );
		}
	}

	private function upsert_lead( array $data ): void {
		$email = (string) ( $data['email'] ?? '' );

		$existing = get_posts(
			array(
				'post_type'      => 'asp_lead',
				'meta_key'       => 'email',
				'meta_value'     => $email,
				'posts_per_page' => 1,
			)
		);

		$title = trim(
			sprintf( '%s %s', (string) ( $data['first_name'] ?? '' ), (string) ( $data['last_name'] ?? '' ) )
		);

		$post_data = array(
			'post_type'   => 'asp_lead',
			'post_title'  => '' !== $title ? $title : 'Untitled Lead',
			'post_status' => 'publish',
		);

		if ( ! empty( $existing ) ) {
			$post_data['ID'] = $existing[0]->ID;
			$post_id         = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, 'email', $email );
		update_post_meta( $post_id, 'phone', (string) ( $data['phone'] ?? '' ) );
		update_post_meta( $post_id, 'company', (string) ( $data['company'] ?? '' ) );
		update_post_meta( $post_id, 'lead_score', (int) ( $data['score'] ?? 0 ) );
	}
}
