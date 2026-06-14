---
title: JSON Imports
description: Import large JSON files with streaming reads and optional JSON Pointer selection.
---

JSON imports stream a local JSON file with `halaxa/json-machine` and schedule items in chunks.

Use `juvo\AS_Processor\Imports\JSON` when the source is a large JSON array or when the records live inside a nested JSON field.

## Configuration

Set these public properties in your import class when needed:

- `$chunk_size`: records per chunk. Default: `10`.
- `$pointer`: optional JSON Pointer for selecting nested records.

## Minimal JSON import

```php
use Generator;
use juvo\AS_Processor\Imports\JSON;

final class Lead_JSON_Import extends JSON {
    public int $chunk_size = 100;
    public ?string $pointer = '/leads';

    public function get_sync_name(): string {
        return 'my_lead_json_import';
    }

    public function schedule(): void {
        as_enqueue_async_action( $this->get_sync_name() );
    }

    protected function get_source_path(): string {
        return WP_CONTENT_DIR . '/uploads/leads.json';
    }

    protected function process_chunk_data( Generator $chunk_data ): void {
        foreach ( $chunk_data as $lead ) {
            // Create or update one lead.
        }
    }
}
```

## JSON Pointer

Use `$pointer` when the records are nested.

For this file:

```json
{
  "leads": [
    { "email": "a@example.com" }
  ]
}
```

Set:

```php
public ?string $pointer = '/leads';
```

## Notes

The source file is deleted after chunk jobs are scheduled. Keep a separate original copy when needed.
