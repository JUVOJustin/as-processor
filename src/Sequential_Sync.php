<?php

namespace juvo\AS_Processor;

use SplQueue;

abstract class Sequential_Sync implements Syncable
{

    use Sync_Data;

    /**
     * Stores all the sync tasks in a queue
     *
     * @var SplQueue
     */
    private SplQueue $queue;

    private array $jobs;

    /**
     * Contains the hook name if the current sync being executed
     *
     * @var ?Sync
     */
    private ?Sync $current_sync = null;

    public function __construct() {
        $this->sync_data_name = $this->get_sync_name();

        // Run always on initialisation
        $this->queue_init();

        // Run the callback function once action is triggered to start the process
        add_action($this->get_sync_name(), [$this, 'callback']);

        // Delete sync data after sync is complete
        add_action($this->get_sync_name() . '/complete', [$this, 'delete_sync_data'], 999);
    }

    /**
     * Syncs/Jobs that are in a sequence are not instantiated on every page load, which is why their hook callbacks are not registered.
     * This function goes through all jobs and registers their hooks
     *
     * @return void
     */
    protected function queue_init(): void
    {
        // Since get-Jobs returns fresh instances of Sync, the hooks of the respective sync are always added
        $this->jobs = $this->get_jobs();

        foreach($this->jobs as $job) {

            // Overwrite sync data name to share data between jobs
            $job->set_sync_data_name($this->get_sync_name());

            // Registering the "next" function to the "complete" hook is essential to run the next job in the sequence
            add_action($job->get_sync_name() . '/complete', [$this, 'next']);
        }
    }

    /**
     * Retrieves and restores data for the sync process.
     *
     * @return void
     * @throws \Exception
     */
    private function retrieve_data(): void
    {

        $data = $this->get_sync_data();

        if (empty($data) || empty($data['queue'])) {
            $this->queue = new SplQueue();
        } else {

            // Restore data from run
            $this->queue = $data['queue'];
            $this->current_sync = $data['current_sync'] ?? null;
        }
    }

    /**
     * Runs the next job in the queue
     * @throws \Exception
     */
    public function next(): void
    {

        // Prepare Data
        $this->retrieve_data();

        // If queue is empty we either never started or we are done
        if ($this->queue->isEmpty()) {

            // If current sync is filled it means this is not the first call
            if ($this->current_sync) {

                // Reset data
                $this->current_sync = null;
                $this->update_sync_data([
                    'queue' => $this->queue,
                    'current_sync' => null
                ]);

                // Allow working on data after sync is complete
                do_action($this->get_sync_name() . '/complete');
            }
            return;
        }

        $sync = $this->queue->dequeue();
        $this->current_sync = $sync;

        // Save current queue back to db
        $this->update_sync_data([
            'queue' => $this->queue,
            'current_sync' => $this->current_sync
        ]);

        // Execute Sync
        do_action($this->current_sync->get_sync_name());
    }

    /**
     * Handles the callback for the job enqueue process.
     * Retrieves the sync data and enqueues each job.
     * Starts the job processing.
     *
     * @return void
     * @throws \Exception
     */
    public function callback(): void
    {

        // Prepare Data
        $this->retrieve_data();

        // Check if is already running
        if($this->current_sync) {
            throw new \Exception("Sync already started");
        }

        $jobs = $this->jobs;
        if (empty($jobs)) {
            return;
        }

        foreach($jobs as $job) {
            $this->enqueue($job);
        }

        $this->next();
    }

    /**
     * Adds a task to the queue
     *
     * @param Sync $task Task to be added, could be a callback or any data type representing a task
     * @return bool
     * @throws \Exception
     */
    private function enqueue(Sync $task): bool
    {

        // Only enqueue new jobs if the queue did not start yet
        if($this->current_sync) {
            return false;
        }

        $this->queue->enqueue($task);
        $this->update_sync_data([
            'queue' => $this->queue,
        ]);

        return true;
    }

    /**
     * Retrieves an array of jobs that need to be synced.
     *
     * The implementing class should provide the logic for retrieving the jobs.
     *
     * @return Sync[] An array of jobs that need to be synced.
     */
    abstract protected function get_jobs(): array;

}
