<?php
/**
 * Handles the database.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\DB;

use DateTimeImmutable;
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
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
	 * @return array<string, mixed>|false Returns an array representing the row if found, or false if the row does not exist.
	 */
	public function get_row_by_id( int $id ): false|array {
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
	 * Retrieves a single Chunk from a DB query.
	 *
	 * @param string $query The SQL Query to get the Chunk from.
	 * @return ?Chunk
	 */
	public function get_chunk( string $query ): ?Chunk {
		$row = $this->db->get_row( $query, ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return Chunk::from_array( $row );
	}

	/**
	 * Retrieves the action with the longest execution duration from the specified group.
	 *
	 * @param string $group_name The name of the group to retrieve the slowest action from.
	 * @return Chunk|null The Chunk object representing the slowest action in the group,
	 *                    or null if no matching action is found.
	 */
	public function get_slowest_action( string $group_name ): ?Chunk {
		$query = $this->db->prepare(
			'SELECT *, (end - start) as duration 
            FROM ' . $this->get_table_name() . '
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL 
            ORDER BY duration DESC 
            LIMIT 1',
			$group_name
		);
		$chunk = $this->get_chunk( $query );

		if ( ! $chunk ) {
			return null;
		}

		return $chunk;
	}

	/**
	 * Retrieves the fastest action (chunk) for the specified group based on execution duration.
	 *
	 * @param string $group_name The name of the group to filter actions by.
	 * @return Chunk|null The fastest Chunk object based on duration, or null if no matching actions are found.
	 */
	public function get_fastest_action( string $group_name ): ?Chunk {
		$query = $this->db->prepare(
			'SELECT *, (end - start) as duration 
            FROM ' . $this->get_table_name() . '
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL 
            ORDER BY duration ASC 
            LIMIT 1',
			$group_name
		);
		$chunk = $this->get_chunk( $query );

		if ( ! $chunk ) {
			return null;
		}

		return $chunk;
	}

	/**
	 * Retrieves chunks from the database filtered by the provided group name and statuses.
	 *
	 * @param string                             $group_name The group name to filter chunks by.
	 * @param ProcessStatus|ProcessStatus[]|null $status Optional. A single status, an array of statuses, or null.
	 *                                          If null, no status filter is applied.
	 *                                          If an array, all provided statuses are included in the filter.
	 *                                          If a single status, only chunks matching that status are returned.
	 * @return array An array of Chunk objects matching the specified group name and status conditions.
	 *               Returns an empty array if no results are found.
	 */
	public function get_chunks_by_status( string $group_name, ProcessStatus|array|null $status = null ): array {

		// If no status is provided, don't filter or include all possible statuses
		if ( null === $status ) {
			$query = $this->db->prepare(
				'SELECT * FROM ' . $this->get_table_name() . ' WHERE `group` = %s',
				$group_name
			);
		} else {
			// Handle single status or multiple statuses
			$statuses      = is_array( $status ) ? $status : array( $status );
			$status_values = array_map(
				static fn( ProcessStatus $status ): string => $status->value,
				$statuses
			);

			$placeholders = array_fill( 0, count( $status_values ), '%s' );
			$query        = $this->db->prepare(
				'SELECT * FROM ' . $this->get_table_name() . '
            WHERE `group` = %s AND status IN (' . implode( ',', $placeholders ) . ')',
				array_merge( array( $group_name ), $status_values )
			);
		}

		// Execute the query and fetch results
		$results = self::db()->get_results( $query, ARRAY_A );
		if ( empty( $results ) ) {
			return array();
		}

		// Map results to Chunk objects
		return array_map(
			function ( $row ) {
				return Chunk::from_array( $row );
			},
			$results
		);
	}

	/**
	 * Retrieves the total count of actions for a specified group name.
	 *
	 * @param string $group_name The group name to filter actions by.
	 * @return int The total number of actions associated with the specified group name.
	 */
	public function get_total_actions( string $group_name ): int {
		$query = $this->db->prepare(
			'SELECT COUNT(*) FROM ' . $this->get_table_name() . '
            WHERE `group` = %s',
			$group_name
		);

		return (int) $this->db->get_var( $query );
	}

	/**
	 * Calculates the synchronization duration for a specified group.
	 *
	 * @param string $group_name The name of the group for which the synchronization duration is calculated.
	 * @param bool   $human_time Optional. Whether to return the duration in a human-readable format. Defaults to false.
	 *                             If true, returns the duration as a string. If false, returns the duration as a float in seconds.
	 * @return float|string|false Returns the synchronization duration as a float in seconds or a string in human-readable format,
	 *                            depending on the $human_time parameter. Returns false if the start or end time is not available.
	 */
	public function get_sync_duration( string $group_name, bool $human_time = false ): float|string|false {
		$query = $this->db->prepare(
			'SELECT MIN(start) as sync_start, MAX(end) as sync_end 
            FROM ' . $this->get_table_name() . '
            WHERE `group` = %s',
			$group_name
		);

		$result = $this->db->get_row( $query );

		if ( empty( $result->sync_start ) || empty( $result->sync_end ) ) {
			return false;
		}

		$duration = round( (float) $result->sync_end - (float) $result->sync_start, 4 );

		if ( $human_time ) {
			return Helper::human_time_diff_microseconds( 0, $duration );
		}

		return $duration;
	}

	/**
	 * Retrieves the earliest sync start time for the specified group.
	 *
	 * @param string $group_name The name of the group to retrieve the sync start time for.
	 *                           Used to filter the records in the database.
	 * @return DateTimeImmutable|null The earliest sync start time as a DateTimeImmutable object.
	 *                                 Returns null if no valid start time is found.
	 */
	public function get_sync_start( string $group_name ): ?DateTimeImmutable {
		$query = $this->db->prepare(
			'SELECT start
            FROM ' . $this->get_table_name() . '
            WHERE `group` = %s
            AND start IS NOT NULL
            ORDER BY start ASC
            LIMIT 1',
			$group_name
		);

		$start = $this->db->get_var( $query );

		if ( empty( $start ) ) {
			return null;
		}

		return Helper::convert_microtime_to_datetime( $start );
	}

	/**
	 * Retrieves the most recent synchronization end time for the specified group.
	 *
	 * @param string $group_name The group name to retrieve the most recent synchronization end time for.
	 *
	 * @return DateTimeImmutable|null The most recent end time as a DateTimeImmutable object, or null if no end time is found.
	 */
	public function get_sync_end( string $group_name ): ?DateTimeImmutable {
		$query = $this->db->prepare(
			'SELECT end
            FROM ' . $this->get_table_name() . '
            WHERE `group` = %s
            AND end IS NOT NULL
            ORDER BY end DESC
            LIMIT 1',
			$group_name
		);

		$end = $this->db->get_var( $query );

		if ( empty( $end ) ) {
			return null;
		}

		return Helper::convert_microtime_to_datetime( $end );
	}

	/**
	 * Calculates the average duration of actions for the specified group.
	 *
	 * @param string $group_name The name of the group to calculate the average action duration for.
	 * @param bool   $human_time Optional. Whether to return the duration as a human-readable string.
	 *                             If true, the duration is formatted for readability.
	 *                             If false, the duration is returned as a float representing microseconds.
	 * @return float|string The average action duration. Returns a float in microseconds if $human_time is false,
	 *                      or a human-readable string if $human_time is true.
	 */
	public function get_average_action_duration( string $group_name, bool $human_time = false ): float|string {
		$query = $this->db->prepare(
			'SELECT AVG(end - start) as avg_duration 
            FROM ' . $this->get_table_name() . '
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL',
			$group_name
		);

		$average = (float) $this->db->get_var( $query );

		return $human_time ?
			Helper::human_time_diff_microseconds( 0, $average ) :
			$average;
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
	 * Checks if all chunks of a specific group (sync) are completed.
	 *
	 * @param string $group_name The name of the group/sync to check.
	 * @return bool|null Returns true if all chunks are completed, false if some are pending, null if no chunks found.
	 */
	public function are_all_chunks_completed( string $group_name ): ?bool {
		// First check if there are any chunks in this group
		$total_query = $this->db->prepare(
			"SELECT COUNT(*) FROM {$this->get_table_name()} WHERE `group` = %s",
			$group_name
		);

		$total_chunks = (int) $this->db->get_var( $total_query );

		if ( 0 === $total_chunks ) {
			return null; // No chunks found for this group
		}

		// Check for incomplete chunks
		$query = $this->db->prepare(
			"SELECT COUNT(*) FROM {$this->get_table_name()} WHERE `group` = %s AND `status` != %s",
			$group_name,
			ProcessStatus::FINISHED->value
		);

		$incomplete_chunks_count = (int) $this->db->get_var( $query );

		// If the count is 0, it means all chunks are completed
		return 0 === $incomplete_chunks_count;
	}


	/**
	 * Retrieves the latest "n" distinct sync groups with their start and end timestamps.
	 *
	 * @param string $sync_name The sync name prefix to filter group names (e.g., "dmz_file_import").
	 * @param int    $limit The maximum number of groups to retrieve. Defaults to 10.
	 * @return array<array{group_name: string, start: ?DateTimeImmutable, end: ?DateTimeImmutable}> An associative array where keys are group names
	 *                                                                                   and values contain start/end DateTimeImmutable objects.
	 *                                                                                   End is only set if all chunks are complete.
	 */
	public function get_latest_sync_groups( string $sync_name, int $limit = 10 ): array {
		// Get groups ordered by latest activity
		$groups_query = $this->db->prepare(
			"SELECT DISTINCT `group`
			FROM {$this->get_table_name()} 
			WHERE `group` LIKE %s
			GROUP BY `group`
			ORDER BY MAX(created_at) DESC 
			LIMIT %d",
			$sync_name . '%',
			$limit
		);

		$group_names = $this->db->get_col( $groups_query );

		if ( empty( $group_names ) ) {
			return array();
		}

		$groups = array();
		
		// Get start/end times and completion status for each group
		foreach ( $group_names as $group_name ) {
			// Get start time and check if all chunks are complete
			$stats_query = $this->db->prepare(
				"SELECT 
					MIN(start) as sync_start,
					MAX(end) as sync_end,
					COUNT(*) as total_chunks,
					SUM(CASE WHEN status IN (%s, %s, %s) THEN 1 ELSE 0 END) as completed_chunks
				FROM {$this->get_table_name()}
				WHERE `group` = %s",
				ProcessStatus::FINISHED->value,
				ProcessStatus::FAILED->value,
				ProcessStatus::CANCELLED->value,
				$group_name
			);
			
			$stats = $this->db->get_row( $stats_query, ARRAY_A );

			$groups[] = array(
				'group_name' => $group_name,
				'start' => ! empty( $stats['sync_start'] )
					? Helper::convert_microtime_to_datetime( $stats['sync_start'] )
					: null,
				'end'   => ( $stats['total_chunks'] === $stats['completed_chunks'] && ! empty( $stats['sync_end'] ) )
					? Helper::convert_microtime_to_datetime( $stats['sync_end'] )
					: null
			);
		}

		return $groups;
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

		// Convert UNIX timestamp to MySQL datetime format for created_at comparison
		$cleanup_datetime = gmdate( 'Y-m-d H:i:s', $cleanup_timestamp );

		/**
		 * Filters the status of chunks to clean up.
		 *
		 * @param ProcessStatus $status The status to filter by (default: 'all').
		 */
		$status = apply_filters( 'asp/chunks/cleanup/status', ProcessStatus::ALL );

		if ( ProcessStatus::ALL === $status ) {
			$query = $this->db->prepare(
				"DELETE FROM {$this->get_table_name()} WHERE (start < %f OR created_at < %s)",
				$cleanup_timestamp,
				$cleanup_datetime
			);
		} else {
			$query = $this->db->prepare(
				"DELETE FROM {$this->get_table_name()} WHERE status = %s AND (start < %f OR created_at < %s)",
				$status->value,
				$cleanup_timestamp,
				$cleanup_datetime
			);
		}

		$this->db->query( $query );
	}
}
