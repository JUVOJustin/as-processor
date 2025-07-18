# Sync Registry Pattern Documentation

## Overview

The AS Processor now includes a registry pattern that automatically registers all Sync implementations and exposes them via REST API. This allows for runtime discovery and management of all sync processes.

## How It Works

### 1. Automatic Registration

Every class that extends `Sync` is automatically registered when instantiated:

```php
class My_Custom_Import extends Import {
    // Your sync is automatically registered when instantiated
}
```

### 2. Required Methods

All Sync implementations must implement these abstract methods:

```php
abstract public function get_sync_name(): string;      // Unique identifier
abstract public function get_display_name(): string;   // Human-readable name
abstract public function get_description(): string;    // Description
```

### 3. REST API Endpoints

The following REST API endpoints are available:

- `GET /wp-json/as-processor/v1/syncs` - List all registered syncs
- `GET /wp-json/as-processor/v1/syncs/{key}` - Get specific sync info
- `POST /wp-json/as-processor/v1/syncs/{key}/trigger` - Trigger a sync
- `GET /wp-json/as-processor/v1/syncs/{key}/status` - Get sync status

## Example Implementation

```php
namespace My_Plugin;

use juvo\AS_Processor\Imports\CSV;

class Product_CSV_Import extends CSV {
    
    public function get_sync_name(): string {
        return 'product-csv-import';
    }
    
    public function get_display_name(): string {
        return __( 'Product CSV Import', 'my-plugin' );
    }
    
    public function get_description(): string {
        return __( 'Import products from CSV files', 'my-plugin' );
    }
    
    protected function get_source_path(): string {
        return wp_upload_dir()['basedir'] . '/imports/products.csv';
    }
    
    public function import_chunk( int $chunk_id ): void {
        $chunk_data = $this->get_chunk_data( $chunk_id );
        
        foreach ( $chunk_data as $row ) {
            // Import logic here
        }
    }
}

// Initialize the import (this registers it automatically)
add_action( 'init', function() {
    new Product_CSV_Import();
} );
```

## API Response Examples

### List All Syncs
```json
[
  {
    "key": "product-csv-import",
    "name": "Product CSV Import",
    "description": "Import products from CSV files",
    "type": "import",
    "group": "product-csv-import_1642598400",
    "status": {
      "is_running": false,
      "has_pending": false,
      "last_run": "2024-01-15T10:30:00+00:00",
      "next_run": null
    }
  }
]
```

### Trigger Sync
```json
{
  "action_id": 12345,
  "message": "Product CSV Import triggered successfully",
  "sync_key": "product-csv-import"
}
```

## Integration with Existing Code

The registry pattern is fully backwards compatible. Existing sync implementations will continue to work without modification, but to take advantage of the REST API, you need to:

1. Implement the three required abstract methods
2. Ensure your sync class is instantiated during WordPress initialization

## Security

All REST API endpoints require the `manage_options` capability by default. You can customize this by extending the `Sync_REST_Controller` class.

## Benefits

1. **Discovery**: List all available syncs at runtime
2. **Management**: Trigger and monitor syncs via REST API
3. **Integration**: Easy integration with external systems
4. **Monitoring**: Check sync status programmatically
5. **Extensibility**: Third-party plugins can register their own syncs