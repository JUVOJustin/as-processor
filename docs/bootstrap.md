---
description: Register AS Processor runtime hooks from a WordPress plugin.
---

# Bootstrap AS Processor

Call `AS_Processor::register()` once from your plugin bootstrap after Composer's autoloader is available:

```php
use juvo\AS_Processor\AS_Processor;

require_once __DIR__ . '/vendor/autoload.php';

AS_Processor::register();
```

The method registers the shared cleanup callback and the Action Scheduler recurring-action check. It is idempotent, so multiple calls in the same request are safe.

You do not need to call it on a specific WordPress hook such as `plugins_loaded`. Call it early enough that Action Scheduler requests have the `asp/cleanup` callback registered.

`AS_Processor::register()` checks only the current request's in-memory WordPress hook registry. It does not query the database. It should still run on every request because WordPress hook callbacks are not persisted between requests.

Import classes are separate from the shared runtime bootstrap. Keep import instances alive on requests where their Action Scheduler callbacks may run.

## Cleanup Action

The cleanup job uses the global `asp/cleanup` hook. Action Scheduler ensures the recurring action through `action_scheduler_ensure_recurring_actions`; when the action is missing, AS Processor schedules it to run daily at midnight.

The cleanup action removes expired chunk tracking rows and expired sync data.
