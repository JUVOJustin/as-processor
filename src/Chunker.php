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
use juvo\AS_Processor\Entities\ProcessStatus;
use juvo\AS_Processor\Entities\Chunk;

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

        // create the new chunk
        $chunk = new Chunk();
        $chunk->set_name( $this->get_sync_name() );
        $chunk->set_group( $this->get_sync_group_name() );
        $chunk->set_status( ProcessStatus::SCHEDULED );
        $chunk->set_data( $chunkData );
        $chunk->save();

        as_enqueue_async_action(
            $this->get_sync_name() . '/process_chunk',
            [
                'chunk_id' => $chunk->get_chunk_id()
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
        // set the new status of the chunk
        $chunk = new Chunk( $chunk_id );
        $chunk->set_status( ProcessStatus::RUNNING );
        $chunk->save();

        // fetch the data
        $data = $chunk->get_data();

        // Convert array to Generator
        $generator = (function () use ($data) {
            foreach ($data as $key => $value) {
                yield $key => $value;
            }
        })();

        $this->process_chunk_data($generator);
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
        /**
         * Filters the number of days to keep chunk data.
         *
         * @param int $interval The interval (e.g., 14).
         */
        $days_interval = apply_filters( 'asp/chunks/cleanup/days', 14 );
        $days_interval = (int) $days_interval * DAY_IN_SECONDS;
        $cleanup_timestamp = (int) time() - $days_interval;

        /**
         * Filters the status of chunks to clean up.
         *
         * @param string $status The status to filter by (default: 'all').
         */
        $status = apply_filters('asp/chunks/cleanup/status', ProcessStatus::ALL->value);

        $query = '';
        if ( 'all' === $status ) {
            $query = $this->db()->prepare(
                "DELETE FROM {$this->get_chunks_table_name()} WHERE start < %f",
                $cleanup_timestamp
            );
        } else {
            $query = $this->db()->prepare(
                "DELETE FROM {$this->get_chunks_table_name()} WHERE status = %s AND start < %f",
                $status,
                $cleanup_timestamp
            );
        }

        $this->db()->query( $query );
    }
}
