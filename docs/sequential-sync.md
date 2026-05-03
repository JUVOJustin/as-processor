---
title: Sequential Syncs
description: Run multiple AS Processor jobs in order and share handoff data between them.
---

Sequential syncs run several `Sync` or `Import` jobs one after another.

Use `juvo\AS_Processor\Sequential_Sync` when a later job depends on work completed by an earlier job. For example, import products first, then import leads that need the product count or product IDs produced by the first import.

## How Sequential Syncs Work

1. The sequence root action runs `Sequential_Sync::callback()`.
2. The sequence stores a queue of child jobs in shared sync data.
3. The first child job is scheduled as a normal Action Scheduler action.
4. The child job runs its own import or sync lifecycle.
5. When the child fires `{child_sync_name}/finish`, the sequence schedules the next child.
6. When the queue is empty, the sequence fires `{sequence_sync_name}/complete`.

The sequence advances on `/finish`, not on `/complete`, so a child import must finish all chunk actions before the next child starts.

## Minimal Sequence

```php
use Generator;
use juvo\AS_Processor\Sequential_Sync;
use juvo\AS_Processor\Sync;

final class Product_Then_Lead_Import extends Sequential_Sync {
    public function get_sync_name(): string {
        return 'product_then_lead_import';
    }

    public function schedule(): void {
        as_enqueue_async_action( $this->get_sync_name() );
    }

    /**
     * @return Sync[]
     */
    protected function get_jobs(): array {
        return array(
            new Product_CSV_Import(),
            new Lead_JSON_Import(),
        );
    }

    protected function process_chunk( int $chunk_id ): void {
    }

    protected function process_chunk_data( Generator $chunk_data ): void {
    }
}
```

Instantiate the sequence on requests where Action Scheduler callbacks may run, just like regular imports.

## Shared Sync Data

Regular syncs store data in a run-scoped store. The namespace follows the Action Scheduler group for that run.

Sequential syncs add a shared store for child jobs. The shared store is used for queue state and for handoff data between jobs.

Use `update_sync_data()` and `get_sync_data()` from child jobs when the data should be visible to another child in the same sequence:

```php
use ActionScheduler_Action;
use juvo\AS_Processor\Imports\CSV;
use juvo\AS_Processor\Imports\JSON;

final class Product_CSV_Import extends CSV {
    public const PRODUCT_COUNT_KEY = 'product_count';

    public function on_finish(): void {
        $this->update_sync_data(
            self::PRODUCT_COUNT_KEY,
            (int) ( wp_count_posts( 'product' )->publish ?? 0 )
        );
    }
}

final class Lead_JSON_Import extends JSON {
    public function track_scheduling_action( ActionScheduler_Action $action ): void {
        parent::track_scheduling_action( $action );

        $product_count = (int) $this->get_sync_data( Product_CSV_Import::PRODUCT_COUNT_KEY );
    }
}
```

The child still keeps its own run-scoped store for lifecycle data such as `finish_fired_at` and import scheduling markers. Shared handoff data and run-local lifecycle data do not use the same namespace.

## Running One Sequence at a Time

A `Sequential_Sync` stores queue state under the sequence sync name. Do not enqueue the same sequence again while it is already running. If `callback()` sees an active current job, it throws `Sync already started`.

Use separate sequence classes with distinct `get_sync_name()` values when two workflows need to run independently.
