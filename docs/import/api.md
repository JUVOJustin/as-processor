---
title: API Imports
description: Understand how paginated API imports fetch pages, schedule chunks, and continue safely.
---

API imports read paginated data and convert the response items into chunk jobs.

Use `juvo\AS_Processor\Imports\API` when the source is not a local file and the next request depends on a page, offset, or URL.

## Flow

1. The root Action Scheduler job calls `split_data_into_chunks()`.
2. The import calls your `process_fetch()` method.
3. `process_fetch()` fetches one page and sets the next index with `set_next_page()`, `set_next_offset()`, or `set_next_url()`.
4. AS Processor adds the returned records to pending items.
5. Complete chunks are scheduled immediately.
6. If there is enough request time left, the same action fetches another page.
7. If there is no time left, a follow-up fetch action is scheduled with the next index.
8. When the API has no next page, remaining pending items are scheduled as the final chunk.

This keeps the queue full sooner: one API action can schedule many chunk jobs instead of alternating one fetch job and one processing job at a time.

## Time budget

API imports avoid running too close to PHP or Action Scheduler time limits.

The usable fetch time is based on the smaller of:

- `max_execution_time`, when PHP has one.
- `action_scheduler_queue_runner_time_limit`, which defaults to `30` seconds.

The import keeps `5` seconds free by default. Override the protected `$execution_time_buffer` property or use the `asp/api/execution_time_buffer` filter to change that buffer.

## Fetch limits

By default, an API action uses the time budget to decide how many pages to fetch.

Set `$max_fetches_per_request` in the import class, or use the `asp/api/max_fetches_per_request` filter, when the remote API needs a hard per-request cap.

```php
add_filter(
    'asp/api/max_fetches_per_request',
    static fn (): int => 5
);
```

## Request interval

Set `$time_between_requests` to add a delay between API calls.

Short intervals are handled inside the current PHP request when there is enough time left. Intervals of `15` seconds or more are scheduled as a future single action so the PHP request is not held open.

## Minimal API import

```php
use Generator;
use juvo\AS_Processor\Imports\API;

final class Product_API_Import extends API {
    public int $chunk_size = 100;
    protected string|int $index = 1;
    protected float $time_between_requests = 0.25;

    public function get_sync_name(): string {
        return 'my_product_api_import';
    }

    public function schedule(): void {
        as_enqueue_async_action( $this->get_sync_name() );
    }

    protected function process_fetch(): array {
        $response = wp_remote_get( 'https://example.com/products?page=' . (int) $this->index );
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );

        $this->set_next_page( (int) $body['total_pages'] );

        return $body['data'] ?? array();
    }

    protected function process_chunk_data( Generator $chunk_data ): void {
        foreach ( $chunk_data as $product ) {
            // Create or update one product.
        }
    }
}
```

## Pagination helpers

- `set_next_page( $total_pages )` for page-based APIs.
- `set_next_offset( $total_items, $per_page )` for offset-based APIs.
- `set_next_url( $next_url )` for APIs that return a next link.

Call one helper during every `process_fetch()` run. If the next index is not set, the import fails early.
