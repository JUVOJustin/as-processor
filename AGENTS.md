# AS Processor — Testing Guide

This repository ships with two PHPUnit 9 test suites, and both run inside the `@wordpress/env` `tests-cli` container:

1. **Unit tests** (`tests/*.php`) — run from the library root with the root `phpunit.xml`.
2. **Application tests** (`tests/e2e/demo-plugin/tests/php/*.php`) — run from the demo plugin fixture against a real WordPress instance. A demo plugin inside the same folder acts as the test fixture and exercises every feature of the library.

## Layout

```
.wp-env.json                              # Demo plugin + monorepo mappings
package.json                              # env:*, test:unit, test:e2e
phpunit.xml                               # Unit test config (repository root, PHPUnit 9)
packages/
├── core/
│   ├── composer.json
│   └── src/
├── csv/
│   ├── composer.json
│   └── src/
├── excel/
│   ├── composer.json
│   └── src/
├── json/
│   ├── composer.json
│   └── src/
└── api/
    ├── composer.json
    └── src/
tests/
├── HelperTest.php                        # Unit tests
├── SyncDataTest.php
└── e2e/
    └── demo-plugin/                      # Full WordPress plugin, mapped into wp-env
        ├── as-processor-demo.php         # Plugin bootstrap
        ├── composer.json                 # Requires split packages via path repos
        ├── bin/generate-excel.php        # Regenerates the XLSX fixture
        ├── data/                         # Fixtures (CSV, JSON, XLSX)
        ├── src/                          # PSR-4 AS_Processor_Demo\
        │   ├── Plugin.php
        │   ├── Product_CSV_Import.php
        │   ├── Lead_JSON_Import.php
        │   ├── Product_Excel_Import.php
        │   ├── Product_API_Import.php
        │   ├── Rest/Mock_Products_Controller.php
        │   └── Support/Demo_Fixture_Manager.php
        └── tests/
            ├── composer.json             # PHPUnit 9 + yoast/phpunit-polyfills
            ├── bootstrap.php             # Loads WP_TESTS_DIR/includes/bootstrap.php
            ├── phpunit.xml               # E2E PHPUnit 9 config
            ├── support/                  # E2E_Test_Case, Action_Scheduler_Test_Helper
            └── php/                      # Integration tests
```

The runtime is split into Composer subpackages under `packages/`. The demo plugin installs those packages directly through path repositories inside the container, while the repository root remains the developer-facing bundle package. The extra `.wp-env.json` mapping exposes the monorepo source to Composer inside the container. Action Scheduler remains a transitive dependency of the split runtime.

Test code is not part of the delivered runtime package. The root `composer.json` keeps `tests/` in `autoload-dev`, `.gitattributes` marks test and tooling files as `export-ignore`, and Composer archive exclusions mirror that packaging boundary.

## Running tests

### Prerequisites

- Node.js 18+
- Docker

### Unit tests

```bash
npm install
npm run env:start
npm run test:unit
npm run env:stop
```

`test:unit` runs inside the wp-env `tests-cli` container with `--env-cwd=wp-content/plugins/as-processor-library-src`. It performs a root `composer install` in the container, which resolves the local package paths under `packages/`, and then executes PHPUnit against the root `phpunit.xml`.

### Application (E2E) tests

```bash
npm install
npm run env:start
npm run test:e2e
npm run env:stop
```

`test:e2e` runs inside the wp-env `tests-cli` container. It performs two composer installs (the demo plugin's split package deps, then its `tests/` deps) and then executes PHPUnit against `tests/phpunit.xml`.

### All tests

```bash
npm install
npm run env:start
npm test
npm run env:stop
```

### Run the unit suite directly in wp-env

```bash
wp-env run tests-cli --env-cwd=wp-content/plugins/as-processor-library-src \
  bash -lc 'composer install --no-interaction && vendor/bin/phpunit -c phpunit.xml'
```

### Run a single test group

All integration tests are annotated with PHPUnit `@group` attributes: `csv`, `json`, `excel`, `api`, `lifecycle`, `database`, `comprehensive`.

```bash
wp-env run tests-cli --env-cwd=wp-content/plugins/as-processor-demo \
  bash -lc 'composer install --no-interaction && composer install --working-dir=tests --no-interaction && tests/vendor/bin/phpunit -c tests/phpunit.xml --group csv'
```

## How the tests exercise Action Scheduler

Integration tests follow the pattern from the [WordPress Plugin Boilerplate Action Scheduler guide](https://github.com/JUVOJustin/wordpress-plugin-boilerplate/blob/main/docs/integrations/action-scheduler.mdx):

1. **Schedule** the root import action via `as_enqueue_async_action()`.
2. **Assert** the action is queued (count, hook, arguments).
3. **Execute** the root action through the real `ActionScheduler_QueueRunner`.
4. **Drain** all follow-up actions (chunk processors, further API fetches) until the queue for that sync is empty.
5. **Assert** the final WordPress state — post counts, post meta, chunk-tracking table, lifecycle hook firings.

This path is identical to production. Tests never call import callbacks directly.

The shared helpers live in `tests/e2e/demo-plugin/tests/support/`:

- `Action_Scheduler_Test_Helper` — `run_action()`, `run_all_pending()`, `run_until_idle()`.
- `E2E_Test_Case` — `schedule_import()`, `run_root_action_and_get_group()`, `run_sync_to_completion()`, `assert_sync_finished()`.

## Integration test matrix

| Test file | Import | Fixture | Chunks | Hooks tested |
|---|---|---|---|---|
| `CSV_Import_Test.php` | `Product_CSV_Import` | 15 rows | 3 | dispatcher → 3 chunks |
| `JSON_Import_Test.php` | `Lead_JSON_Import` | 12 rows (via `/leads` pointer) | 3 | dispatcher → 3 chunks |
| `Excel_Import_Test.php` | `Product_Excel_Import` | 10 rows | 2 | dispatcher → 2 chunks |
| `API_Import_Test.php` | `Product_API_Import` | 35 items, 10/page | varies | 4 paginated fetches + chunks |
| `Sync_Lifecycle_Test.php` | CSV sync | 15 rows | 3 | `/start`, `/complete`, `/finish` |
| `Chunk_Database_Test.php` | CSV sync + cleanup | 15 rows | 3 | `Chunk_DB` stats API + `asp/cleanup` |
| `Comprehensive_Workflow_Test.php` | All four | Combined | — | End-to-end with no cross-contamination |

## Continuous integration

`.github/workflows/test-analyse.yml` runs three sequential jobs:

1. **`call-install-deps`** — shared install step from `install-deps.yml`.
2. **`test`** — PHPStan and PHPCS.
3. **`wp-env-tests`** — starts wp-env once and runs both the unit and E2E suites inside `tests-cli` after the static checks pass.

## Code changes

Always run both suites before committing:

```bash
npm install
npm run env:start
npm test
npm run env:stop
```

Add tests alongside new features. If the feature is a new `Import` subclass, add a corresponding import class to the demo plugin and an integration test that covers it.

## Troubleshooting

**`WP_TESTS_DIR is not set`** — You're running phpunit outside the wp-env tests container. Use `npm run test:unit` or `npm run test:e2e`.

**Docker issues** — `docker system prune -f && npm run env:destroy && npm run env:start`.

**Port conflicts** — set `WP_ENV_PORT` and `WP_ENV_TESTS_PORT` explicitly when running `wp-env`, for example `WP_ENV_PORT=8900 WP_ENV_TESTS_PORT=8901 npm run env:start`.

**Excel fixture missing** — The test bootstrap regenerates it automatically. To force regeneration:

```bash
wp-env run cli bash -lc "cd /var/www/html/wp-content/plugins/as-processor-demo && php bin/generate-excel.php"
```
