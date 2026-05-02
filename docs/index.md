---
title: Imports
description: Learn how AS Processor imports split source data into Action Scheduler chunk jobs.
---

Imports turn a large data source into small Action Scheduler jobs.

Use an import when you need to read many records from a file without processing everything in one PHP request.

## How imports work

1. A root action runs the import's `split_data_into_chunks()` method.
2. The import reads the source data and creates chunks.
3. Each chunk is stored in the AS Processor chunk table.
4. Each chunk gets its own `{sync_name}/process_chunk` Action Scheduler job.
5. The chunk job loads the stored data and passes it to `process_chunk_data()`.
6. When all fetch and chunk actions finish, the sync fires `{sync_name}/finish`.

The source is only responsible for producing records. Your import class is responsible for deciding what to do with each record.

## Base contract

Every import needs a deterministic sync name and chunk processing logic.

```php
use Generator;
use juvo\AS_Processor\Imports\CSV;

final class Product_Import extends CSV {
    protected int $chunk_size = 500;

    public function get_sync_name(): string {
        return 'my_product_import';
    }

    public function schedule(): void {
        as_enqueue_async_action( $this->get_sync_name() );
    }

    protected function get_source_path(): string {
        return WP_CONTENT_DIR . '/uploads/products.csv';
    }

    protected function process_chunk_data( Generator $chunk_data ): void {
        foreach ( $chunk_data as $row ) {
            // Create or update one item.
        }
    }
}
```

## Supported sources

- [CSV imports](./import/csv/) for delimited text files.
- [JSON imports](./import/json/) for large JSON files, including JSON Pointer support.
- [Excel imports](./import/excel/) for `.xlsx` spreadsheets.

## Chunk size

Set `chunk_size` according to the cost of processing one record.

Small chunks are safer for slow writes or complex transformations. Larger chunks reduce Action Scheduler overhead when each record is cheap to process.

## Source cleanup

File imports delete their source file after chunks are scheduled. Keep the source path pointed at a temporary runtime copy when the original file must be preserved.
