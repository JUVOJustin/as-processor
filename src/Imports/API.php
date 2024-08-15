<?php

namespace juvo\AS_Processor\Imports;

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
     * @var bool|string|int $current_index
     */
    protected bool|string|int $next = 0;

    /**
     * Sets the time between requests in seconds. Default = 1/4 sec
     *
     * @var float $time_between_requests
     */
    protected float $time_between_requests = 0.25;

    /**
     * The size of the chunks
     *
     * @var int $chunk_size
     */
    public int $chunk_size = 100;

    /**
     * Makes a call to the api
     *
     * @param int|null $index
     * @return void
     * @throws Exception
     */
    public function split_data_into_chunks(?int $index = null): void
    {

        // Maybe set current index. Default value can be set with class parameter in child implementation
        if ($index != null) {
            $this->index = $index;
        }

        // Check if last request is at least the configured interval ago
        $last_request = $this->get_sync_data('last_request') ?: 0;
        $last_request = ($last_request + $this->time_between_requests) * 1000000; // Both are in seconds
        $now = (int)(microtime(true) * 1000000);                                  // Convert current time to microseconds

        if ($last_request > $now) {                  // If last request + interval is in the future
            $sleep_time = (int)$last_request - $now; // Time to sleep in microseconds

            // Check if sleep time is longer than 1 second (1,000,000 microseconds). Workaround required as stated in php docs
            if ($sleep_time >= 1000000) {
                $seconds = ($sleep_time / 1000000);                // Extract seconds
                $microseconds = $sleep_time % 1000000;             // Extract remaining microseconds
                sleep((int)$seconds);                              // Sleep for the seconds part
                usleep($microseconds);                             // Sleep for the remaining microseconds
            } else {
                usleep($sleep_time); // Sleep for durations less than 1 second
            }
        }

        $data = $this->process_fetch();

        if (empty($data)) {
            throw new Exception('No items received from the request');
        }

        // It is required that the developer sets the next index during the request implementation so we know when to end scheduling more requests
        if ($this->next === 0 || $this->next === "") {
            throw new Exception('You need to use one of the "set_next_*" methods during your request');
        }

        // Get the pending items added by other requests
        $existing_items = $this->get_sync_data('pending_items') ?: [];

        // Add current items to the pending items
        $items = array_merge($existing_items, $data);

        // If chunk size threshold is reached schedule the chunk.
        if (count($items) >= $this->chunk_size) {

            $this->schedule_chunk(array_slice($items, 0, $this->chunk_size));

            // Keep the remaining elements in the array
            $items = array_slice($items, $this->chunk_size);
        }

        // If this was the last request schedule chunk as well. Else schedule request
        if ($this->next === false) {
            $this->schedule_chunk($items);
        } else {
            if ($this->time_between_requests >= 15) {
                // Longer request intervals (> 15 sec) are scheduled since they would unnecessarily keep php requests alive
                as_schedule_single_action(
                    (int)ceil(microtime(true) + $this->time_between_requests),
                    $this->get_sync_name(),
                    ['index' => $this->next],
                    $this->get_sync_group_name());
            } else {
                // Queue next request as async because wait interval under 10sec can be handled in one request
                as_enqueue_async_action($this->get_sync_name(), [
                    'index' => $this->next
                ], $this->get_sync_group_name());
            }
        }

        $this->update_sync_data([
            'pending_items' => $items,
            'last_request'  => microtime(true),
        ]);
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
}
