The **AS Processor** library is a robust synchronization and data chunking framework designed specifically for WordPress environments. Leveraging asynchronous task management through the Action Scheduler, it provides a flexible and efficient orchestration for large-scale data processing tasks, such as API synchronizations, file-based (CSV, Excel, JSON) imports, and seamless chunk-wise data management.

### Core Features
1. **Data Chunking and Processing**:
    - The library introduces a consistent chunking mechanism to split large datasets (from files or APIs) into smaller, manageable pieces. Each chunk is processed asynchronously, reducing memory usage and improving load balancing.
    - Multiple data sources are supported, including **API endpoints**, **Excel files**, **CSV files**, and **JSON files**.

2. **Asynchronous Task Management**:
    - Powered by the awesome [Action Scheduler](https://actionscheduler.org/), tasks can be managed asynchronously, ensuring smooth execution without blocking other site processes.
    - Tasks are queued for execution, with efficient handling of task timeouts, retries, and cancellations.

3. **Data Source Adaptability**:
    - The library provides abstract, extensible classes for different data formats:
        - **CSV Processor (`CSV_Sync`)**: Handles CSV imports split into chunks, supporting UTF-8 conversions, custom delimiters, optional headers, and efficient file cleanup.
        - **Excel Processor (`Excel`)**: Manages Excel files with optional headers and the ability to process specific worksheets. Includes support for rich text cell values and row skipping.
        - **JSON Processor (`JSON`)**: Enables chunked processing of JSON files and supports JSON Pointer for extracting specific portions of the data.
        - **API Processor (`API`)**: Works with paginated APIs, automatically handles rate limiting, request intervals, and pagination (by page, offset, or URL).

4. **Highly Extensible and Customizable**:
    - Abstract classes allow developers to implement their own data-fetching or processing methods tailored to their use case.
    - A robust foundation supports advanced features like progressive pagination, deep merging, lock management, and transient-based sync data storage.

5. **Reliable Sync Management**:
    - Built-in sync lifecycle management, including hooks for starting, progressing, and completing synchronizations.
    - Automatic cleanup of completed tasks and retention of synchronization data for specified durations.

6. **Error Handling and Recovery**:
    - Exception handling is seamlessly integrated at every critical stage, ensuring the process fails gracefully and any issues are recorded for diagnosis.
    - Built-in support for handling job timeouts, cancellations, and retries with appropriate sync lifecycle callbacks.

### Use Cases
- **Importing Data**: Import and process data from large datasets such as customer lists in Excel, product catalogs in JSON, or user data from CSV files.
- **API Data Sync**: Synchronize large-scale data from external APIs, with built-in features like pagination handling and rate limiting.
- **Scheduled and Batch Processing**: Execute complex batch processing tasks (like order processing or generating reports) asynchronously without affecting website performance.
- **Custom Data Processing Flows**: Build scalable workflows for chunking and processing any large dataset with minimal memory consumption and maximum fault tolerance.

### Technical Highlights
1. **Core Components**:
    - The `Chunker` trait schedules and processes data chunks, leveraging Action Scheduler for asynchronous execution.
    - The `Sync` class serves as an abstract base that manages the entire synchronization process lifecycle.
    - The `Sync_Data` trait provides reliable synchronization data storage using WordPress's transients, enabling flexible data sharing and locking mechanisms.

2. **Focus on Memory Efficiency**:
    - Uses iterative processing (e.g., PHP Generators, chunk-based file reading) to minimize memory usage and ensure scalability for large datasets.

3. **Modular Design**:
    - The clean separation of concerns allows developers to modify or extend specific aspects like chunk processing or API fetching independently.

4. **Action Scheduler Integration**:
    - Uses the Action Scheduler's event-driven architecture to manage background tasks effectively, along with group-level synchronization to maintain context-aware processing.

### Ideal For
This library is an excellent choice for WordPress developers and enterprises dealing with:
- High-volume data integration from various sources.
- Automating repetitive and resource-intensive synchronization tasks.
- Optimizing workflows for applications that rely on large datasets or slow APIs.
- Frequent e-commerce product imports

AS Processor combines the power of modern WordPress development practices, Action Scheduler's asynchronous processing capabilities, and a highly abstracted framework to enable seamless and fault-tolerant data processing at scale. It offers developers a solid foundation for building efficient, scalable synchronization solutions tailored to their applications' unique requirements.

---

## Sync Lifecycle Hooks

The `Sync` class provides a comprehensive set of lifecycle hooks that allow you to monitor and respond to various stages of the synchronization process. These hooks are namespaced by your sync name (as returned by `get_sync_name()`).

### Available Hooks

#### `{sync_name}/start`
**When**: Fired when an action begins execution  
**Parameters**: None (triggered via `track_action_start`)  
**Use case**: Log the start of processing, set up temporary resources, or track progress

#### `{sync_name}/complete`
**When**: Fired for **each** sync-owned action upon successful completion  
**Parameters**: 
- `ActionScheduler_Action $action` - The completed action object
- `int $action_id` - The ID of the completed action

**Use case**: Track individual action completions, update progress indicators, or perform per-action cleanup

#### `{sync_name}/finish`
**When**: Fired **once** when all actions in the sync group are complete  
**Parameters**:
- `string $group_name` - The sync group name

**Use case**: Final cleanup, send completion notifications, or trigger dependent processes

#### `{sync_name}/fail`
**When**: Fired when an action encounters an exception during execution  
**Parameters**:
- `ActionScheduler_Action $action` - The failed action object
- `Exception $e` - The exception that was thrown
- `int $action_id` - The ID of the failed action

**Use case**: Error logging, send failure notifications, or trigger recovery processes

#### `{sync_name}/cancel`
**When**: Fired when an action is manually cancelled  
**Parameters**:
- `ActionScheduler_Action $action` - The cancelled action object
- `int $action_id` - The ID of the cancelled action

**Use case**: Clean up resources, log cancellation events

#### `{sync_name}/timeout`
**When**: Fired when an action times out  
**Parameters**:
- `ActionScheduler_Action $action` - The timed-out action object
- `int $action_id` - The ID of the timed-out action

**Use case**: Handle timeout scenarios, log timeout events, retry logic

### Hook Usage Example

```php
add_action( 'my_custom_sync/finish', function( $group_name ) {
    error_log( "All actions in group $group_name are finished!" );
    // Perform final cleanup or send notifications
} );

add_action( 'my_custom_sync/fail', function( $action, $exception, $action_id ) {
    error_log( "Action $action_id failed: " . $exception->getMessage() );
}, 10, 3 );
```

### Overridable Methods

Instead of using hooks, you can override these methods in your child class:

#### `on_finish()`
Called when all actions in the sync group are complete. This is the preferred method for implementing group completion logic.

```php
public function on_finish(): void {
    // Your completion logic here
}
```

#### `on_fail()`
Called when an action fails.

```php
public function on_fail(): void {
    // Your failure handling logic here
}
```

---

## Migration Guide

### Migrating from `on_complete()` to `on_finish()`

**Important**: The `on_complete()` method has been deprecated in favor of the new lifecycle hook system.

#### What Changed?

1. **New Hook Semantics**:
   - `{sync_name}/complete` now fires **per-action** (for every completed action in the sync)
   - `{sync_name}/finish` now fires **once** when all actions are complete (previous behavior of `/complete`)

2. **Method Rename**:
   - `on_complete()` → `on_finish()` (same behavior, just renamed for clarity)

#### Migration Steps

**If you override `on_complete()` in your child class:**

```php
// OLD (deprecated)
public function on_complete(): void {
    // Your completion logic
}

// NEW (recommended)
public function on_finish(): void {
    // Your completion logic (same code)
}
```

**If you hook into `{sync_name}/complete` externally:**

```php
// OLD (will not be called now)
add_action( 'my_sync/complete', function( $group_name ) {
    // This was called once when all actions finished
});

// NEW (fires once when all actions are finished)
add_action( 'my_sync/finish', function( $group_name ) {
    // This is called once when all actions finished
} );
```

#### Backward Compatibility

The library maintains backward compatibility:
- The `on_complete()` method still exists and will automatically call `on_finish()` if it's overridden
- A deprecation warning (`E_USER_DEPRECATED`) is triggered only when `on_complete()` is actually executed
- You have time to migrate, but should do so to avoid future issues

#### Breaking Changes

⚠️ **External hooks on `{sync_name}/complete` will now fire multiple times** (once per action instead of once per group). If you have external code hooking into this, you **must** update it to use `{sync_name}/finish` for group-completion behavior.

---
