<?php
/**
 * Chunk Entity
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\Entities;

use Exception;
use DateTimeImmutable;
use JsonSerializable;
use juvo\AS_Processor\DB;
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\Helper;

/**
 * The chunk entity class
 */
class Chunk implements JsonSerializable {

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
	 * An array that holds fields to be excluded from JSON serialization or processing.
	 *
	 * @var string[]
	 */
	private array $excluded_json_fields = array();

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
		if ( $this->is_data_fetched || empty( $this->chunk_id ) ) {
			return;
		}

		$row = Chunk_DB::db()->get_row_by_id( $this->chunk_id );
		self::from_array( $row, $this );

		$this->is_data_fetched = true;
	}

	/**
	 * Get the chunk ID
	 *
	 * @return int|null
	 */
	public function get_chunk_id(): ?int {
		return $this->chunk_id;
	}

	/**
	 * Get the action ID
	 *
	 * @return int|null
	 * @throws Exception Unparsable Date.
	 */
	public function get_action_id(): ?int {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->action_id;
	}

	/**
	 * Get the group
	 *
	 * @return string|null
	 * @throws Exception Unparsable Date.
	 */
	public function get_group(): ?string {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->group;
	}

	/**
	 * Get the status
	 *
	 * @return ProcessStatus|null
	 * @throws Exception Unparsable Date.
	 */
	public function get_status(): ?ProcessStatus {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->status;
	}

	/**
	 * Get the data
	 *
	 * @return array|null
	 * @throws Exception Unparsable Date.
	 */
	public function get_data(): ?array {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->data;
	}

	/**
	 * Get the start time
	 *
	 * @return DateTimeImmutable|null
	 * @throws Exception Unparsable Date.
	 */
	public function get_start(): ?DateTimeImmutable {
		if ( ! $this->is_data_fetched ) {
			$this->fetch_data();
		}
		return $this->start;
	}

	/**
	 * Get the end time
	 *
	 * @return ?DateTimeImmutable
	 * @throws Exception Unparsable Date.
	 */
	public function get_end(): ?DateTimeImmutable {
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

		// Calculate duration using `U.u` format for precise timestamps
		$end_time   = (float) $this->end->format( 'U.u' );
		$start_time = (float) $this->start->format( 'U.u' );

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
	 * @param ?DateTimeImmutable $date The start time to set.
	 *
	 * @return void
	 */
	public function set_start( ?DateTimeImmutable $date = null ): void {

		if ( empty( $date ) ) {
			$date = new DateTimeImmutable();
		}

		$this->start = $date;
	}

	/**
	 * Sets the end date and time.
	 *
	 * @param ?DateTimeImmutable $date The end date and time.
	 * @return void
	 */
	public function set_end( ?DateTimeImmutable $date = null ): void {

		if ( empty( $date ) ) {
			$date = new DateTimeImmutable();
		}

		$this->end = $date;
	}

	/**
	 * Sets the logs.
	 *
	 * @param array $logs An array of log data.
	 * @return void
	 */
	private function set_logs( array $logs ): void {
		$this->logs = $logs;
	}

	/**
	 * Saves the chunk data to the database
	 *
	 * @throws Exception If database operation fails.
	 * @return int the chunk id.
	 */
	public function save(): int {

		$result = DB\Chunk_DB::db()->replace( $this->to_insert() );

		if ( false === $result ) {
			throw new Exception( esc_attr__( 'Could not insert chunk data!', 'as-processor' ) );
		}

		$this->chunk_id = (int) $result;

		// Reset data fetched flag to ensure fresh data on next fetch
		$this->is_data_fetched = false;
		return $this->chunk_id;
	}

	/**
	 * Prepares data for database insertion based on the class properties.
	 *
	 * @return array The associative array containing the prepared data for insertion.
	 */
	private function to_insert(): array {
		$data = array();

		if ( null !== $this->chunk_id ) {
			$data['id'] = $this->chunk_id;
		}

		if ( null !== $this->group ) {
			$data['group'] = $this->group;
		}

		if ( null !== $this->status ) {
			$data['status'] = $this->status->value;
		}

		if ( null !== $this->data ) {
			$data['data'] = serialize( $this->data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		if ( null !== $this->action_id ) {
			$data['action_id'] = $this->action_id;
		}

		if ( null !== $this->start ) {
			$data['start'] = (float) $this->start->format( 'U.u' );
		}

		if ( null !== $this->end ) {
			$data['end'] = (float) $this->end->format( 'U.u' );
		}

		return $data;
	}

	/**
	 * Creates a Chunk instance from an array of data.
	 *
	 * Constructs a new Chunk object if one is not provided, and populates it using the data from the array.
	 * Deserializes data and sets various properties on the Chunk object based on the input array.
	 *
	 * @param array      $data Array of data used to populate the Chunk object.
	 * @param Chunk|null $chunk Optional Chunk instance to update. If null, a new instance will be created.
	 *
	 * @return Chunk
	 */
	public static function from_array( array $data, ?Chunk $chunk = null ): Chunk {

		if ( empty( $chunk ) ) {
			$chunk = new Chunk( $data['id'] ?? null );
		}

		// Check for each value before calling the corresponding setter
		if ( ! empty( $data['data'] ) ) {
			$chunk->set_data( unserialize( $data['data'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}

		if ( ! empty( $data['action_id'] ) ) {
			$chunk->set_action_id( (int) $data['action_id'] );
			$chunk->set_logs( Chunk_DB::db()->get_logs( $data['action_id'] ) );
		}

		if ( ! empty( $data['status'] ) ) {
			$chunk->set_status( ProcessStatus::from( $data['status'] ) );
		}

		if ( ! empty( $data['start'] ) ) {
			$chunk->set_start( Helper::convert_microtime_to_datetime( $data['start'] ) );
		}

		if ( ! empty( $data['end'] ) ) {
			$chunk->set_end( Helper::convert_microtime_to_datetime( $data['end'] ) );
		}

		return $chunk;
	}

	/**
	 * Sets the fields to be excluded from JSON serialization.
	 *
	 * @param array $fields An array of field names to exclude during serialization.
	 * @return Chunk
	 */
	public function setJsonExcludedFields( array $fields ): Chunk {
		$this->excluded_json_fields = $fields;
		return $this;
	}

	/**
	 * Specify data which should be serialized to JSON.
	 * Elements can be excluded from the JSON by using "setJsonExcludedFields()"
	 *
	 * @return array
	 * @throws Exception Unparsable date.
	 */
	public function jsonSerialize(): array {
		$all_data = array(
			'chunk_id'  => $this->get_chunk_id(),
			'action_id' => $this->get_action_id(),
			'group'     => $this->get_group(),
			'status'    => $this->get_status()?->value,
			'data'      => $this->get_data(),
			'start'     => $this->get_start() ? $this->get_start()->format( DateTimeImmutable::ATOM ) : null,
			'end'       => $this->get_end() ? $this->get_end()->format( DateTimeImmutable::ATOM ) : null,
			'logs'      => $this->get_logs(),
			'duration'  => $this->get_duration(),
		);

		// Exclude the json excluded fields
		foreach ( $this->excluded_json_fields as $field ) {
			unset( $all_data[ $field ] );
		}
		return $all_data;
	}
}
