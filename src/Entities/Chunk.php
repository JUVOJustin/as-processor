<?php
/**
 * Chunk Entity
 *
 * @package juvo\AS_Processor
 */
namespace juvo\AS_Processor\Entities;

use Exception;
use DateTimeImmutable;
use DateInterval;
use juvo\AS_Processor\Helper;
use juvo\AS_Processor\DB;

/**
 * The chunk entity class
 */
class Chunk
{
    use DB;

    /**
     * @var int|null
     */
    private ?int $chunk_id = null;

    /**
     * @var int|null
     */
    private ?int $action_id = null;

    /**
     * @var string|null
     */
    private ?string $name = null;

    /**
     * @var string|null
     */
    private ?string $group = null;

    /**
     * @var ProcessStatus|null
     */
    private ?ProcessStatus $status = null;

    /**
     * @var array<mixed>|null
     */
    private ?array $data = null;

    /**
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $start = null;

    /**
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $end = null;

    /**
     * @var bool
     */
    private bool $is_data_fetched = false;

    /**
     * @var array<object>
     */
    private array $logs = [];

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
     */
    private function fetch_data(): void {
        if ( $this->is_data_fetched ) {
            return;
        }

        $data_query = $this->db()->prepare(
            "SELECT * FROM {$this->get_chunks_table_name()} WHERE id = %d",
            $this->chunk_id
        );
        $data = $this->db()->get_row( $data_query );

        if ( $data ) {
            $this->action_id = (int) $data->action_id;
            $this->name     = $data->name;
            $this->group    = $data->group;
            $this->status   = ProcessStatus::from($data->status);
            $this->data     = unserialize( $data->data );
            $this->start    = Helper::convert_microtime_to_datetime( $data->start );
            $this->end      = Helper::convert_microtime_to_datetime( $data->end );

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
     */
    public function get_action_id(): int {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->action_id;
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function get_name(): string {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->name;
    }

    /**
     * Get the group
     *
     * @return string
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
    public function get_logs(): array
    {
        return $this->logs;
    }

    /**
     * Get the duration in seconds
     *
     * @return float Returns the duration in seconds with microsecond precision
     */
    public function get_duration(): float
    {
        if (!$this->is_data_fetched) {
            $this->fetch_data();
        }

        if (null === $this->end || null === $this->start) {
            return 0.0;
        }

        /** @var DateTimeImmutable $end */
        $end = $this->end;

        /** @var DateTimeImmutable $start */
        $start = $this->start;

        // Get timestamps with microseconds
        $end_time = (float) sprintf('%d.%d', $end->getTimestamp(), (int) $end->format('u') / 1000);
        $start_time = (float) sprintf('%d.%d', $start->getTimestamp(), (int) $start->format('u') / 1000);

        // Simple subtraction gives us the duration in seconds
        return $end_time - $start_time;
    }

    /**
     * Sets the action ID
     *
     * @param int $action_id The action ID
     * @return void
     */
    public function set_action_id(int $action_id): void {
        $this->action_id = $action_id;
    }

    /**
     * Sets the name
     *
     * @param string $name The name
     * @return void
     */
    public function set_name(string $name): void {
        $this->name = $name;
    }

    /**
     * Sets the group
     *
     * @param string $group The group
     * @return void
     */
    public function set_group(string $group): void {
        $this->group = $group;
    }

    /**
     * Sets the status
     *
     * @param ProcessStatus $status The status
     * @return void
     */
    public function set_status(ProcessStatus $status): void {
        $this->status = $status;
    }

    /**
     * Sets the data
     *
     * @param array<mixed> $data The data
     * @return void
     */
    public function set_data(array $data): void {
        $this->data = $data;
    }

    /**
     * Sets the start time
     *
     * @param float $microtime The microtime
     * @return void
     */
    public function set_start(float $microtime): void {
        $this->start = Helper::convert_microtime_to_datetime($microtime);
    }

    /**
     * Sets the end time
     *
     * @param float $microtime The microtime
     * @return void
     */
    public function set_end(float $microtime): void {
        $this->end = Helper::convert_microtime_to_datetime($microtime);
    }

    /**
     * Saves the chunk data to the database
     *
     * @throws Exception If database operation fails
     * @return int the chunk id
     */
    public function save(): int {
        $data = [];

        // Only add fields that have been explicitly set
        if (null !== $this->name) {
            $data['name'] = $this->name;
        }

        if (null !== $this->group) {
            $data['group'] = $this->group;
        }

        if (null !== $this->status) {
            $data['status'] = $this->status->value;
        }

        if (null !== $this->data) {
            $data['data'] = serialize($this->data);
        }

        if (null !== $this->action_id) {
            $data['action_id'] = $this->action_id;
        }

        // Format start time if exists
        if (null !== $this->start) {
            $data['start'] = (float) sprintf(
                '%d.%d',
                $this->start->getTimestamp(),
                (int) $this->start->format('u') / 1000
            );
        }

        // Format end time if exists
        if (null !== $this->end) {
            $data['end'] = (float) sprintf(
                '%d.%d',
                $this->end->getTimestamp(),
                (int) $this->end->format('u') / 1000
            );
        }

        if (empty($data)) {
            throw new Exception( __( 'Data is empty', 'as-processor' ) ); // Nothing to save
        }

        $formats = [];
        foreach ($data as $key => $value) {
            $formats[] = in_array($key, ['action_id'], true) ? '%d' : '%s';
        }

        // Insert or update based on chunk_id existence
        if (empty($this->chunk_id)) {
            $result = $this->db()->insert(
                $this->get_chunks_table_name(),
                $data,
                $formats
            );

            if (false === $result) {
                throw new Exception(__( 'Could not insert chunk data!', 'as-processor' ) );
            }

            $this->chunk_id = (int) $this->db()->insert_id;
        } else {
            $result = $this->db()->update(
                $this->get_chunks_table_name(),
                $data,
                ['id' => $this->chunk_id],
                $formats,
                ['%d']
            );

            if (false === $result) {
                throw new Exception(__( 'Failed to update chunk data', 'as-processor' ) );
            }
        }

        // Reset data fetched flag to ensure fresh data on next fetch
        $this->is_data_fetched = false;
        return $this->chunk_id;
    }
}