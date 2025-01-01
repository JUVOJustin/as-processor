<?php
/**
 * Chunk Entity
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\Entities;

use Exception;
use DateTimeImmutable;
use juvo\AS_Processor\Helper;
use juvo\AS_Processor\DB;

/**
 * The chunk entity class
 */
class Chunk {

	use DB;

	/**
	 * ID of the chunk.
	 *
	 * @var int|null
	 */
	private ?int $chunk_id = null;

	/**
	 * If of the "action scheduler" action that processes this chunk.
	 *
	 * @var int|null
	 */
	private ?int $action_id = null;

	/**
	 * Name of the group the chunk and the action belong to.
	 *
	 * @var string|null
	 */
	private ?string $group = null;

	/**
	 * Status of the chunk.
	 *
	 * @var ProcessStatus|null
	 */
	private ?ProcessStatus $status = null;

	/**
	 * Chunk data. This is the data that is being processed.
	 *
	 * @var array<mixed>|null
	 */
	private ?array $data = null;

	/**
	 * Time when the chunk processing has been started.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $start = null;

	/**
	 * Time when the chunk processing has ended.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $end = null;

	/**
	 * Flag to check if the data has been fetched from the DB.
	 *
	 * @var bool
	 */
	private bool $is_data_fetched = false;

	/**
	 * Log of the action fetched from "action scheduler" DB table
	 *
	 * @var array<object>
	 */
	private array $logs = array();

	/**
	 * Constructor
	 *
	 * @param int|null $chunk_id The chunk ID.
	 */
	public function __construct( ?int $chunk_id = null ) {
		$this->chunk_id = $chunk_id;
	}

	/**
	 * Fetches data from database
	 *
	 * @return void
	 * @throws Exception Unparsable date.
	 */
	private function fetch_data(): void {
		if ( $this->is_data_fetched ) {
			return;
		}

		$data_query = $this->db()->prepare(
			"SELECT * FROM {$this->get_chunks_table_name()} WHERE id = %d",
			$this->chunk_id
		);
		$data       = $this->db()->get_row( $data_query );

		if ( $data ) {
			$this->action_id = (int) $data->action_id;
			$this->group     = $data->group;
			$this->status    = ProcessStatus::from( $data->status );
			$this->data      = unserialize( $data->data );
			$this->start     = Helper::convert_microtime_to_datetime( $data->start );
			$this->end       = Helper::convert_microtime_to_datetime( $data->end );

			// Fetch associated logs
			$logs_query = $this->db()->prepare(
				"SELECT message FROM {$this->db()->prefix}actionscheduler_logs WHERE action_id = %d ORDER BY log_id ASC",
				$this->action_id
			);
			$this->logs = array_column( $this->db()->get_results( $logs_query ), 'message' );
		}

		$this->is_data_fetched = true;
	}

	/**
	 * Get the chunk ID
	 *
	 * @return int
	 */
	public function get_chunk_id(): int {
		return $this->chunk_id;
	}

	/**
	 * Get the action ID
	 *
	 * @return int
	 * @throws Exception Unparsable Date.
	 */
	public function get_action_id(): int {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->action_id;
	}

	/**
	 * Get the group
	 *
	 * @return string
	 * @throws Exception Unparsable Date.
	 */
	public function get_group(): string {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->group;
	}

	/**
	 * Get the status
	 *
	 * @return ProcessStatus
	 * @throws Exception Unparsable Date.
	 */
	public function get_status(): ProcessStatus {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->status;
	}

	/**
	 * Get the data
	 *
	 * @return array<mixed>
	 * @throws Exception Unparsable Date.
	 */
	public function get_data(): array {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->data;
	}

	/**
	 * Get the start time
	 *
	 * @return DateTimeImmutable
	 * @throws Exception Unparsable Date.
	 */
	public function get_start(): DateTimeImmutable {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->start;
	}

	/**
	 * Get the end time
	 *
	 * @return DateTimeImmutable
	 * @throws Exception Unparsable Date.
	 */
	public function get_end(): DateTimeImmutable {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->end;
	}

	/**
	 * Gets the logs of the chunk
	 *
	 * @return array
	 */
	public function get_logs(): array {
		return $this->logs;
	}

	/**
	 * Get the duration in seconds
	 *
	 * @return float Returns the duration in seconds with microsecond precision
	 * @throws Exception Unparsable Date.
	 */
	public function get_duration(): float {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}

		if ( null === $this->end || null === $this->start ) {
			return 0.0;
		}

		$end   = $this->end;
		$start = $this->start;

		// Get timestamps with microseconds
		$end_time   = (float) sprintf( '%d.%d', $end->getTimestamp(), (int) $end->format( 'u' ) / 1000 );
		$start_time = (float) sprintf( '%d.%d', $start->getTimestamp(), (int) $start->format( 'u' ) / 1000 );

		// Simple subtraction gives us the duration in seconds
		return $end_time - $start_time;
	}

	/**
	 * Sets the action ID.
	 *
	 * @param int $action_id The action ID.
	 * @return void
	 */
	public function set_action_id( int $action_id ): void {
		$this->action_id = $action_id;
	}

	/**
	 * Sets the group.
	 *
	 * @param string $group The group name of the chunk.
	 * @return void
	 */
	public function set_group( string $group ): void {
		$this->group = $group;
	}

	/**
	 * Sets the status.
	 *
	 * @param ProcessStatus $status The status.
	 * @return void
	 */
	public function set_status( ProcessStatus $status ): void {
		$this->status = $status;
	}

	/**
	 * Sets the data of the chunk.
	 *
	 * @param array<mixed> $data The data of the chunk. Is getting serialized later.
	 * @return void
	 */
	public function set_data( array $data ): void {
		$this->data = $data;
	}

	/**
	 * Sets the start time.
	 *
	 * @param string|null $microtime Start time of chunk processing as microtime.
	 * @return void
	 */
	public function set_start( ?string $microtime = null ): void {

		if (empty($microtime)) {
			$microtime = (string) microtime(true);
		}

		$this->start = Helper::convert_microtime_to_datetime( $microtime );
	}

	/**
	 * Sets the end time.
	 *
	 * @param string|null $microtime End time of chunk processing as microtime.
	 * @return void
	 */
	public function set_end( ?string $microtime = null ): void {

		if (empty($microtime)) {
			$microtime = (string) microtime(true);
		}

		$this->end = Helper::convert_microtime_to_datetime( $microtime );
	}

	/**
	 * Saves the chunk data to the database
	 *
	 * @throws Exception If database operation fails.
	 * @return int the chunk id.
	 */
	public function save(): int {
		$data = array();

		// Only add fields that have been explicitly set
		if ( null !== $this->group ) {
			$data['group'] = $this->group;
		}

		if ( null !== $this->status ) {
			$data['status'] = $this->status->value;
		}

		if ( null !== $this->data ) {
			$data['data'] = serialize( $this->data );
		}

		if ( null !== $this->action_id ) {
			$data['action_id'] = $this->action_id;
		}

		// Format start time if exists
		if ( null !== $this->start ) {
			$data['start'] = (float) sprintf(
				'%d.%d',
				$this->start->getTimestamp(),
				(int) $this->start->format( 'u' ) / 1000
			);
		}

		// Format end time if exists
		if ( null !== $this->end ) {
			$data['end'] = (float) sprintf(
				'%d.%d',
				$this->end->getTimestamp(),
				(int) $this->end->format( 'u' ) / 1000
			);
		}

		if ( empty( $data ) ) {
			throw new Exception( esc_attr__( 'Data is empty', 'as-processor' ) ); // Nothing to save
		}

		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, array( 'action_id' ), true ) ? '%d' : '%s';
		}

		// Insert or update based on chunk_id existence
		if ( empty( $this->chunk_id ) ) {
			$result = $this->db()->insert(
				$this->get_chunks_table_name(),
				$data,
				$formats
			);

			if ( false === $result ) {
				throw new Exception( esc_attr__( 'Could not insert chunk data!', 'as-processor' ) );
			}

			$this->chunk_id = (int) $this->db()->insert_id;
		} else {
			$result = $this->db()->update(
				$this->get_chunks_table_name(),
				$data,
				array( 'id' => $this->chunk_id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw new Exception( esc_attr__( 'Failed to update chunk data', 'as-processor' ) );
			}
		}

		// Reset data fetched flag to ensure fresh data on next fetch
		$this->is_data_fetched = false;
		return $this->chunk_id;
	}
}
