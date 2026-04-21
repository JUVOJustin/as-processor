---
title: AS Processor
description: Feature-split Composer packages for Action Scheduler based synchronization and imports in WordPress.
---

## Overview

AS Processor is a WordPress-focused synchronization framework built on top of Action Scheduler. The repository is organized as a small Composer package monorepo so consumers can install only the runtime they need.

## Packages

- `juvo/as-processor-core`: Sync lifecycle, chunk scheduling, tracking tables, entities, stats, and shared helpers.
- `juvo/as-processor-csv`: CSV import adapter built on `league/csv`.
- `juvo/as-processor-excel`: Excel import adapter built on `phpoffice/phpspreadsheet`.
- `juvo/as-processor-json`: JSON import adapter built on `halaxa/json-machine`.
- `juvo/as-processor-api`: Paginated API import adapter.
- `juvo/as-processor`: Convenience bundle that requires all of the packages above.

All packages keep the existing `juvo\\AS_Processor\\...` namespace, so the install surface changes without forcing application code to rename classes.

## Installation

Install only the runtime you need:

```bash
composer require juvo/as-processor-core
composer require juvo/as-processor-csv
composer require juvo/as-processor-excel
composer require juvo/as-processor-json
composer require juvo/as-processor-api
```

Install the full bundle when you want every adapter:

```bash
composer require juvo/as-processor
```

## Repository Layout

```text
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
└── e2e/demo-plugin/
```

The root `composer.json` is the install-all bundle used for local development and for users who want the previous monolithic install experience.

## Testing

The repository includes two PHPUnit 9 suites and both run inside the `wp-env` `tests-cli` container.

```bash
npm install
npm run env:start
npm test
npm run env:stop
```

- `npm run test:unit`: installs the root bundle and runs the root `phpunit.xml`.
- `npm run test:e2e`: installs the split packages into the demo plugin fixture and runs the WordPress integration suite.

## Notes

- The demo plugin consumes the split packages directly so the application suite verifies the new package boundaries.
- The root bundle remains the easiest backwards-compatible install path for users who want all adapters together.
