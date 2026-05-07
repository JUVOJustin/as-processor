<?php

namespace juvo\AS_Processor\Imports;

use ActionScheduler_Store;
use Exception;
use juvo\AS_Processor\Import;

abstract class API extends Import
{

    /**
     * The current index. Store either page, the next offset or the next url
     *
     * @var string|int $index
     */
    protected string|int $index = 0;

    /**
     * The next index possible. Stores either the next page, the next offset or the next url.
     * False if there is no request left
     *
     * @var bool|string|int|null $current_index
     */
    protected bool|string|int|null $next = 0;

    /**
     * Sets the time between requests in seconds. Default = 1/4 sec
     *
     * @var float $time_between_requests
     */
    protected float $time_between_requests = 0.25;

    /**
     * Number of seconds to keep free before the request time limit is reached.
     *
     * @var float $execution_time_buffer
     */
    protected float $execution_time_buffer = 5.0;

    /**
     * Maximum number of API fetches to run in one Action Scheduler action.
     * Set to 0 to use the request time budget only.
     *
     * @var int $max_fetches_per_request
     */
    protected int $max_fetches_per_request = 0;

    /**
     * The size of the chunks
     *
     * @var int $chunk_size
     */
    public int $chunk_size = 100;

    /**
     * Makes a call to the api
     *
     * @param int|string|null $index
     * @return void
     * @throws Exception
     */
    public function split_data_into_chunks(int|string|null $index = null): void
    {

        // Maybe set current index. Default value can be set with class parameter in child implementation
        if ($index !== null) {
            $this->index = $index;
        }

        // Get the pending items added by other requests
        $items = $this->get_run_sync_data_store()->get('pending_items') ?: [];

        $started_at = microtime(true);
        $fetches    = 0;

        do {
            $this->wait_until_request_interval_has_elapsed();

            $this->reset_next_index();
            $data = $this->process_fetch();

            if (empty($data)) {
                throw new Exception('No items received from the request');
            }

            $this->assert_next_index_was_set();

            // Add current items to the pending items
            $items = array_merge($items, $data);
            $items = $this->schedule_complete_chunks($items);

            ++$fetches;
            $this->get_run_sync_data_store()->update('last_request', microtime(true));

            if ($this->next === false) {
                break;
            }

            if (! $this->can_fetch_again($started_at, $fetches)) {
                break;
            }

            $this->index = $this->next;
        } while (true);

        // If this was the last request schedule remaining items as well. Else schedule request
        if ($this->next === false) {
            if (! empty($items)) {
                $this->schedule_chunk($items);
                $items = [];
            }
        } else {
            $this->schedule_next_fetch($this->next);
        }

        $this->get_run_sync_data_store()->update('pending_items', $items);
    }

    /**
     * Wait until the configured request interval has elapsed.
     *
     * @return void
     */
    protected function wait_until_request_interval_has_elapsed(): void
    {
        if ($this->time_between_requests <= 0) {
            return;
        }

        // Check if last request is at least the configured interval ago
        $last_request = $this->get_run_sync_data_store()->get('last_request') ?: 0;
        $last_request = ($last_request + $this->time_between_requests) * 1000000; // Both are in seconds
        $now = (int)(microtime(true) * 1000000);                                  // Convert current time to microseconds

        if ($last_request <= $now) {
            return;
        }

        $sleep_time = (int)$last_request - $now; // Time to sleep in microseconds

        // Check if sleep time is longer than 1 second (1,000,000 microseconds). Workaround required as stated in php docs
        if ($sleep_time >= 1000000) {
            $seconds = ($sleep_time / 1000000);    // Extract seconds
            $microseconds = $sleep_time % 1000000; // Extract remaining microseconds
            sleep((int)$seconds);                  // Sleep for the seconds part
            usleep($microseconds);                 // Sleep for the remaining microseconds
            return;
        }

        usleep($sleep_time); // Sleep for durations less than 1 second
    }

    /**
     * Reset pagination state before a fetch so subclasses must set it explicitly.
     *
     * @return void
     */
    protected function reset_next_index(): void
    {
        $this->next = null;
    }

    /**
     * Ensure the fetch implementation reported the next pagination state.
     *
     * @return void
     * @throws Exception When no next index was set during fetching.
     */
    protected function assert_next_index_was_set(): void
    {
        // It is required that the developer sets the next index during the request implementation so we know when to end scheduling more requests
        if ($this->next === null || $this->next === 0 || $this->next === "") {
            throw new Exception('You need to use one of the "set_next_*" methods during your request');
        }
    }

    /**
     * Schedule every complete chunk from the accumulated item list.
     *
     * @param array<mixed> $items Accumulated items waiting for chunking.
     * @return array<mixed> Remaining items below the chunk size threshold.
     * @throws Exception When chunk data insertion fails.
     */
    protected function schedule_complete_chunks(array $items): array
    {
        while (count($items) >= $this->chunk_size) {
            $this->schedule_chunk(array_slice($items, 0, $this->chunk_size));
            $items = array_slice($items, $this->chunk_size);
        }

        return $items;
    }

    /**
     * Determine whether this request can safely perform another API fetch.
     *
     * @param float $started_at Timestamp when the current action started fetching.
     * @param int   $fetches    Number of fetches already executed in this action.
     * @return bool
     */
    protected function can_fetch_again(float $started_at, int $fetches): bool
    {
        $max_fetches = (int) apply_filters('asp/api/max_fetches_per_request', $this->max_fetches_per_request, $this->get_sync_name(), $this->get_sync_group_name());

        if ($max_fetches > 0 && $fetches >= $max_fetches) {
            return false;
        }

        if ($this->time_between_requests >= 15) {
            return false;
        }

        $time_limit = $this->get_fetch_time_limit();

        if ($time_limit <= 0) {
            return true;
        }

        $elapsed                = microtime(true) - $started_at;
        $average_fetch_duration = $elapsed / max(1, $fetches);
        $estimated_next_fetch   = $average_fetch_duration + max(0, $this->time_between_requests);

        return ($elapsed + $estimated_next_fetch) < $time_limit;
    }

    /**
     * Return the usable time budget for fetches in one Action Scheduler action.
     *
     * @return float Number of seconds available for fetch work. 0 means unlimited.
     */
    protected function get_fetch_time_limit(): float
    {
        $limits = [];

        $php_limit = (int) ini_get('max_execution_time');
        if ($php_limit > 0) {
            $limits[] = $php_limit;
        }

        $action_scheduler_limit = (int) apply_filters('action_scheduler_queue_runner_time_limit', 30);
        if ($action_scheduler_limit > 0) {
            $limits[] = $action_scheduler_limit;
        }

        if (empty($limits)) {
            return 0.0;
        }

        $buffer = (float) apply_filters('asp/api/execution_time_buffer', $this->execution_time_buffer, $this->get_sync_name(), $this->get_sync_group_name());

        return max(0.0, min($limits) - max(0.0, $buffer));
    }

    /**
     * Schedule the next API fetch action.
     *
     * @param int|string $index Index, offset, or URL for the next request.
     * @return void
     */
    protected function schedule_next_fetch(int|string $index): void
    {
        if ($this->time_between_requests >= 15) {
            // Longer request intervals are scheduled since they would unnecessarily keep php requests alive
            as_schedule_single_action(
                (int)ceil(microtime(true) + $this->time_between_requests),
                $this->get_sync_name(),
                ['index' => $index],
                $this->get_sync_group_name()
            );
            return;
        }

        // Queue next request as async because short wait intervals can be handled in one request
        as_enqueue_async_action(
            $this->get_sync_name(),
            [
                'index' => $index,
            ],
            $this->get_sync_group_name()
        );
    }

    /**
     * Sets the next page number for pagination.
     *
     * This function calculates and sets the next page number based on the total
     * number of pages available. If the current page is the last one, it sets
     * $this->next to false.
     *
     * @param int $total The total number of pages.
     * @return void
     */
    protected function set_next_page(int $total): void
    {
        if ($this->index < $total) {
            $this->next = $this->index + 1;
        } else {
            $this->next = false;
        }
    }

    /**
     * Sets the next offset for pagination.
     *
     * This function calculates and sets the next offset based on the total
     * number of items available and items per page. If the current offset
     * is at the end of the items list, it sets $this->next to false.
     *
     * @param int $total The total number of items.
     * @param int $per_page The items queried per page
     * @return void
     */
    protected function set_next_offset(int $total, int $per_page): void
    {
        if ($this->index + $per_page < $total) {
            $this->next = $this->index + $per_page;
        } else {
            $this->next = false;
        }
    }

    /**
     * Sets the next link for pagination.
     *
     * This function sets the next URL for pagination based on the provided
     * next URL. If the next URL is empty, it sets $this->next to false.
     *
     * @param string $next The URL for the next page.
     * @return void
     */
    protected function set_next_url(string $next): void
    {
        if (!empty($next)) {
            $this->next = $next;
        } else {
            $this->next = false;
        }
    }

    abstract protected function process_fetch(): mixed;

    /**
     * Check whether an Action Scheduler action performs API fetching.
     *
     * @param \ActionScheduler_Action $action The Action Scheduler action.
     * @return bool
     */
    private function is_fetch_action( \ActionScheduler_Action $action ): bool {
        return $action->get_hook() === $this->get_sync_name();
    }

    /**
     * Track the start time of the action that schedules the chunks.
     *
     * Note: The parent Import implementation identifies the "spawning" action
     * by checking for an empty action group (empty( $action->get_group() )).
     * For API imports we instead rely on the root fetch hook, because API
     * fetching/scheduling actions may use groups differently or share groups
     * with other actions.
     *
     * With this logic, the first API fetching action for which no
     * 'spawning_action_started_at' has been stored is treated as the spawning
     * action. This divergence from the parent class is intentional.
     *
     * Context: API imports have fetching and chunk processing in two parallel
     * running processes. They still belong to one sync though. The first fetch
     * marks the start for a sync. For other imports the start is the first chunk,
     * but that does not apply here.
     *
     * @param \ActionScheduler_Action $action The action being started.
     * @return void
     * @throws Exception When sync data update fails.
     */
    public function track_scheduling_action( \ActionScheduler_Action $action ): void {

        // Track the start of the first api fetching action only
        if ( ! $this->get_run_sync_data_store()->get( 'spawning_action_started_at' ) && $this->is_fetch_action( $action ) ) {
            $this->get_run_sync_data_store()->update( 'spawning_action_started_at', time() );
        }
    }

    /**
     * Tracks the end time of the action that schedules the chunks and triggers the finish action if applicable.
     *
     * Note: The parent Import implementation identifies the "spawning" action completion
     * by checking for an empty action group (empty( $action->get_group() )).
     * For API imports we instead rely on the root fetch hook.
     *
     * Additionally, we query for pending/running fetch actions to ensure all API fetching
     * is complete before marking the spawning action as ended. This ensures the on_finish
     * callback only runs when all fetches are complete, not just when all chunks are finished.
     *
     * KNOWN LIMITATION - Potential Race Condition:
     * The query for pending/running actions (lines below) and the subsequent check are not atomic.
     * Between checking for running actions and updating the sync data, another fetch action could
     * complete. This could theoretically cause:
     * 1. The 'spawning_action_ended_at' to be set prematurely if the last action completes
     *    between the query and the update
     * 2. Multiple actions to update 'spawning_action_ended_at' if several complete simultaneously
     *
     * However, this race condition has minimal practical impact because:
     * - The timestamp will be set to approximately the correct time (within seconds)
     * - Multiple updates will overwrite with similar timestamp values
     * - The finish action trigger still requires all chunks to be completed, providing an
     *   additional safety check
     *
     * A proper fix would require database-level locking or transactional guarantees, which
     * would add significant complexity for marginal benefit in this use case.
     *
     * @param \ActionScheduler_Action $action The action being completed.
     * @return void
     * @throws Exception When sync data update or retrieval fails.
     */
    public function on_complete( \ActionScheduler_Action $action ): void {

        if ( $this->is_fetch_action( $action ) ) {
            $fetches = as_get_scheduled_actions([
                'hook'        => $action->get_hook(),
                'group'       => $this->get_sync_group_name(),
                'status'      => [ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING],
                'per_page'    => 1,
            ]);

            if ( ! empty( $fetches ) ) {
                return; // There are still pending or running fetch actions
            }

            $this->get_run_sync_data_store()->update( 'spawning_action_ended_at', time() );
        }

    }
}
