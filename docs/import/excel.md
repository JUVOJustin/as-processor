---
title: Excel Imports
description: Import XLSX worksheets by reading rows and scheduling them as chunk jobs.
---

Excel imports read a local `.xlsx` file with PhpSpreadsheet and schedule worksheet rows in chunks.

Use `juvo\AS_Processor\Imports\Excel` when the source is a spreadsheet rather than a plain CSV file.

## Configuration

Set these protected properties in your import class when needed:

- `$chunk_size`: rows per chunk. Default: `1000`.
- `$has_header`: whether the first row contains column names. Default: `true`.
- `$worksheet`: optional worksheet name. Falls back to the active sheet.
- `$skip_empty_rows`: whether empty rows are ignored. Default: `true`.

## Minimal Excel import

```php
use Generator;
use juvo\AS_Processor\Imports\Excel;

final class Product_Excel_Import extends Excel {
    protected int $chunk_size = 250;
    protected bool $has_header = true;
    protected string $worksheet = 'Products';

    public function get_sync_name(): string {
        return 'my_product_excel_import';
    }

    public function schedule(): void {
        as_enqueue_async_action( $this->get_sync_name() );
    }

    public function get_source_path(): string {
        return WP_CONTENT_DIR . '/uploads/products.xlsx';
    }

    protected function process_chunk_data( Generator $chunk_data ): void {
        foreach ( $chunk_data as $row ) {
            // Header cells are used as array keys when $has_header is true.
        }
    }
}
```

## Worksheet handling

When `$worksheet` is set, AS Processor tries to load that worksheet by name.

If it cannot find the worksheet, it uses the active worksheet instead.

## Notes

Only `.xlsx` files are supported by the built-in reader.

The source file is deleted after chunk jobs are scheduled. Use a runtime copy if the original spreadsheet should remain untouched.
