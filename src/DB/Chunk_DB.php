<?php
/**
 * Handles the database.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\DB;

use juvo\AS_Processor\Entities\Chunk;
use juvo\AS_Processor\Entities\ProcessStatus;
use juvo\AS_Processor\Helper;

/**
 * Class Chunk_DB
 *
 * Handles database operations for the "asp_chunks" table. This class provides
 * functionality to manage the table schema and ensure its existence within
 * the database.
 */
class Chunk_DB extends Base_DB {


	/**
	 * The name of the table.
	 *
	 * @var string
	 */
	protected string $table_name = 'asp_chunks';

	/**
	 * Define the table schema and create the table if it doesn't exist.
	 *
	 * @return void
	 */
	protected function maybe_create_table(): void {
		$charset_collate = $this->db->get_charset_collate();
		$table_name      = $this->get_table_name();

		$sql = "CREATE TABLE {$table_name} (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `action_id` bigint(20) unsigned DEFAULT NULL,
            `group` varchar(255) NOT NULL,
            `status` varchar(255) NOT NULL,
            `data` longtext NOT NULL,
            `start` decimal(14,4) DEFAULT NULL,
            `end` decimal(14,4) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `status` (`status`),
            KEY `start` (`start`),
            KEY `end` (`end`),
            KEY `action_id` (`action_id`)
        ) {$charset_collate}";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Retrieve a row from the database by its ID.
	 *
	 * @param int $id The ID of the row to retrieve.
	 * @return bool|array Returns an array representing the row if found, or false if the row does not exist.
	 */
	public function get_row_by_id( int $id ): bool|array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->get_table_name()} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: false;
	}

	/**
	 * Retrieves one row from the database
	 *
	 * @param string $query
	 * @return bool|Chunk
	 */
	public function get_chunk( string $query ): bool|Chunk {

		$row = $this->db->get_row( $query, ARRAY_A );

		if ( ! $row ) {
			return false; // No data found
		}

		return Chunk::from_array( $row );
	}

	/**
	 * Gets actions filtered by status.
	 *
	 * @param ProcessStatus|array<ProcessStatus> $status Status to filter by.
	 * @param string                             $group_name
	 * @return Chunk[]
	 */
	public function get_chunks_by_status( ProcessStatus|array $status, string $group_name ): array {
		$statuses      = is_array( $status ) ? $status : array( $status );
		$status_values = array_map( static fn( ProcessStatus $status ): string => $status->value, $statuses );

		$placeholders = array_fill( 0, count( $status_values ), '%s' );
		$query        = $this->db->prepare(
			'SELECT * FROM ' . $this->get_table_name() . '
            WHERE `group` = %s AND status IN (' . implode( ',', $placeholders ) . ')',
			array_merge( array( $group_name ), $status_values )
		);

		$results = self::db()->get_results( $query, ARRAY_A );
		if ( empty( $results ) ) {
			return array();
		}

		$results = array_map(
			function ( $row ) {
				return Chunk::from_array( $row );
			},
			$results
		);

		return $results;
	}

	/**
	 * Inserts or updates a chunk record in the database.
	 * When the chunk ID is provided, the corresponding record is updated.
	 * Otherwise, a new chunk is inserted.
	 *
	 * @param array $data Array representation of the chunk.
	 * @return int|bool The ID of the inserted or updated chunk on success, or false on failure.
	 */
	public function replace( array $data ): int|bool {

		// Prepare database insert logic
		$fields       = array_keys( $data );
		$placeholders = implode( ', ', array_fill( 0, count( $fields ), '%s' ) );
		$field_list   = implode( '`, `', $fields );
		$on_duplicate = implode( ', ', array_map( fn( $field ) => "`$field` = VALUES(`$field`)", $fields ) );

		$query = "
        INSERT INTO {$this->get_table_name()} (`$field_list`)
        VALUES ($placeholders)
        ON DUPLICATE KEY UPDATE $on_duplicate;
    ";

		$result = $this->db->query(
			$this->db->prepare(
				$query,
				...array_values( $data )
			)
		);

		if ( false === $result ) {
			return false; // Indicate the operation failed
		}

		// Check if this was an update (id exists in the data)
		if ( isset( $data['id'] ) ) {
			return (int) $data['id']; // Return the existing ID
		}

		// If no ID was provided (new insert), fetch the last inserted ID
		return (int) $this->db->insert_id;
	}

	/**
	 * Retrieve logs for a specific action ID.
	 *
	 * @param int $action_id The ID of the action for which to retrieve logs.
	 *
	 * @return bool|array|null An array of log messages if found, null if no results, or false on failure.
	 */
	public function get_logs( int $action_id ): bool|array|null {
		$logs_query = $this->db->prepare(
			"SELECT message FROM {$this->db->prefix}actionscheduler_logs WHERE action_id = %d ORDER BY log_id ASC",
			$action_id
		);
		return array_column( $this->db->get_results( $logs_query ), 'message' );
	}

	/**
	 * Cleans up old chunk data based on a specified interval and status.
	 *
	 * @return void
	 */
	public function cleanup(): void {

		/**
		 * Filters the number of days to keep chunk data.
		 *
		 * @param int $interval The interval (e.g., 14*DAY_IN_SECONDS).
		 */
		$interval          = apply_filters( 'asp/chunks/cleanup/interval', 14 * DAY_IN_SECONDS );
		$cleanup_timestamp = (int) time() - $interval;

		/**
		 * Filters the status of chunks to clean up.
		 *
		 * @param ProcessStatus $status The status to filter by (default: 'all').
		 */
		$status = apply_filters( 'asp/chunks/cleanup/status', ProcessStatus::ALL );

		if ( ProcessStatus::ALL === $status ) {
			$query = $this->db->prepare(
				"DELETE FROM {$this->get_table_name()} WHERE start < %f",
				$cleanup_timestamp
			);
		} else {
			$query = $this->db->prepare(
				"DELETE FROM {$this->get_table_name()} WHERE status = %s AND start < %f",
				$status->value,
				$cleanup_timestamp
			);
		}

		$this->db->query( $query );
	}
}
