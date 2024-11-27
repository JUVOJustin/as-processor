<?php
/**
 * Trait Chunker
 *
 * Provides functionality for processing data in chunks using WordPress Action Scheduler.
 * Handles scheduling, processing, and cleanup of chunked data operations.
 *
 * @package juvo\AS_Processor
 */
namespace juvo\AS_Processor;

use Exception;
use Generator;
use Iterator;

/**
 * The Chunker.
 */
trait Chunker
{
    use DB;

    /**
     * Schedules an async action to process a chunk of data. Passed items are serialized and added to a chunk.
     *
     * @param array<mixed>|Iterator<mixed> $chunkData The data to be processed in chunks
     * @throws Exception When chunk data insertion fails
     * @return void
     */
    protected function schedule_chunk(array|Iterator $chunkData): void
    {
        // update chunk counter
        if ( property_exists( $this, 'chunk_counter' ) ) {
            $this->chunk_counter += 1;
        }

        // check if we have a chunk limit
        if ( property_exists( $this, 'chunk_limit' ) && property_exists( $this, 'chunk_counter' ) && $this->chunk_limit != 0 && $this->chunk_counter > $this->chunk_limit ) {
            return;
        }

        // prepare the data for the database
        $data = serialize( $chunkData );
        $name = $this->get_sync_name();
        $status = 'scheduled';

        $query = $this->db()->prepare(
            "INSERT INTO {$this->get_chunks_table_name()}
            (name, status, data, start, end)
            VALUES (%s, %s, %s, NULL, NULL)",
            $name,
            $status,
            $data
        );
        $result = $this->db()->query($query);
        if ( false === $result ) {
            throw new Exception('Could not insert chunk data!');
        }
        $inserted_id = (int) $this->db()->insert_id;

        as_enqueue_async_action(
            $this->get_sync_name() . '/process_chunk',
            [
                'chunk_id' => $inserted_id
            ], // Wrap in array to pass as single argument. Needed because of abstract child method enforcement
            $this->get_sync_group_name()
        );
    }

    /**
     * Callback function for the single chunk jobs.
     * This jobs reads the serialized chunk data from the database and processes it.
     *
     * @param int $chunk_id The ID of the chunk to process
     * @throws Exception When chunk data is empty or invalid
     * @return void
     */
    protected function import_chunk(int $chunk_id): void
    {
        // set the start time of the chunk
        $this->update_chunk( $chunk_id, 'started', current_time('mysql', true) );

        // fetch the data
        $data_query = $this->db()->prepare(
            "SELECT data FROM {$this->get_chunks_table_name()} WHERE id = %s",
            $chunk_id
        );
        $results = $this->db()->get_results( $data_query, ARRAY_A );
        if ( empty( $results[0]['data'] ) ) {
            throw new Exception( 'Empty data' );
        }

        // process the data
        $data = unserialize($results[0]['data']);

        // Convert array to Generator
        $generator = (function () use ($data) {
            foreach ($data as $key => $value) {
                yield $key => $value;
            }
        })();

        $this->process_chunk_data($generator);

        // set the start time of the chunk
        $this->update_chunk( $chunk_id, 'finished', null, current_time('mysql', true) );
    }

    /**
     * Handles the actual data processing. Should be implemented in the class lowest in hierarchy.
     *
     * @param Generator<mixed> $chunkData The generator containing chunk data to process
     * @return void
     */
    abstract function process_chunk_data(Generator $chunkData): void;

    /**
     * Schedules the cleanup job if not already scheduled.
     * Creates a daily cron job at midnight to clean up old chunk data.
     *
     * @return void
     */
    public function schedule_chunk_cleanup(): void
    {
        if ( as_has_scheduled_action( 'ASP/Chunks/Cleanup' ) ) {
			return;
		}

        // schedule the cleanup midnight every day
		as_schedule_cron_action(
			time(),
			'0 0 * * *', 'ASP/Chunks/Cleanup'
		);
    }

    /**
     * Cleans the chunk data table from data with following properties:
     * - older than 2 days (start)
     * - status must be finished
     *
     * @return void
     */
    public function cleanup_chunk_data(): void
    {
        // Get date 2 days ago in MySQL format
        $two_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) );

        $query = $this->db()->prepare(
            "DELETE FROM {$this->get_chunks_table_name()} WHERE status = %s AND start < %s",
            'finished',
            $two_days_ago
        );

        $this->db()->query( $query );
    }

    /**
     * Updates the chunk status and timestamps in the database.
     *
     * @param int $chunk_id The chunk ID to update
     * @param string|null $status The new status to set
     * @param string|null $start The new start timestamp (MySQL format)
     * @param string|null $end The new end timestamp (MySQL format)
     * @return void
     */
    protected function update_chunk( int $chunk_id, string $status = null, string $start = null, string $end = null ): void
    {
        if ( ! $chunk_id ) {
            return;
        }

        $table_name = $this->get_chunks_table_name();
        $data = array();
        $formats = array();

        if ( null !== $status ) {
            $data['status'] = $status;
            $formats[] = '%s';
        }

        if ( null !== $start ) {
            $data['start'] = $start;
            $formats[] = '%s';
        }

        if ( null !== $end ) {
            $data['end'] = $end;
            $formats[] = '%s';
        }

        if ( empty( $data ) ) {
            return;
        }

        $this->db()->update(
            $table_name,
            $data,
            array( 'id' => $chunk_id ),
            $formats,
            array( '%d' )
        );
    }
}
