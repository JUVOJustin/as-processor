---
title: CSV Imports
description: Import delimited text files by splitting CSV records into chunk jobs.
---

CSV imports read a local CSV file with `league/csv` and schedule records in chunks.

Use `juvo\AS_Processor\Imports\CSV` for product feeds, exports from external tools, or any delimited text file.

## Configuration

Set these protected properties in your import class when needed:

- `$chunk_size`: records per chunk. Default: `5000`.
- `$delimiter`: CSV delimiter. Default: `,`.
- `$has_header`: whether the first row contains column names. Default: `true`.
- `$src_encoding`: optional source encoding converted to UTF-8.

## Minimal CSV import

```php
use Generator;
use juvo\AS_Processor\Imports\CSV;

final class Product_CSV_Import extends CSV {
    protected int $chunk_size = 500;
    protected string $delimiter = ';';
    protected bool $has_header = true;

    public function get_sync_name(): string {
        return 'my_product_csv_import';
    }

    public function schedule(): void {
        as_enqueue_async_action( $this->get_sync_name() );
    }

    protected function get_source_path(): string {
        return WP_CONTENT_DIR . '/uploads/products.csv';
    }

    protected function process_chunk_data( Generator $chunk_data ): void {
        foreach ( $chunk_data as $row ) {
            // Header columns are available as array keys when $has_header is true.
        }
    }
}
```

## Notes

The source file must exist before the root action runs.

After chunk jobs are scheduled, the CSV file is deleted. Use a temporary copy if the original upload should stay available.
