---
title: AS Processor
description: Feature-split Composer packages for Action Scheduler based synchronization and imports in WordPress.
---

## Overview

AS Processor is a WordPress-focused synchronization framework built on top of Action Scheduler. The repository is organized as a small Composer package monorepo so consumers can install only the runtime they need.

## Packages

- `juvo/as-processor`: Sync lifecycle, chunk scheduling, tracking tables, entities, stats, and shared helpers.
- `juvo/as-processor-csv`: CSV import adapter built on `league/csv`.
- `juvo/as-processor-excel`: Excel import adapter built on `phpoffice/phpspreadsheet`.
- `juvo/as-processor-json`: JSON import adapter built on `halaxa/json-machine`.
- `juvo/as-processor-api`: Paginated API import adapter.

All packages keep the existing `juvo\\AS_Processor\\...` namespace, so the install surface changes without forcing application code to rename classes.

## Installation

Install the core runtime:

```bash
composer require juvo/as-processor
```

Install optional adapters as needed:

```bash
composer require juvo/as-processor-csv
composer require juvo/as-processor-excel
composer require juvo/as-processor-json
composer require juvo/as-processor-api
```

## Repository Layout

```text
packages/
├── core/
│   ├── src/
│   └── tests/
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

The root `composer.json` is the core package and developer entry point for the monorepo.

## Testing

The repository includes two PHPUnit 9 suites and both run inside the `wp-env` `tests-cli` container.

```bash
npm install
npm run env:start
npm test
npm run env:stop
```

- `npm run test:unit`: installs the root core package and runs the core unit tests from `packages/core/tests` through the root `phpunit.xml`.
- `npm run test:e2e`: installs the split packages into the demo plugin fixture and runs the WordPress integration suite.

## Notes

- The demo plugin consumes the split packages directly so the application suite verifies the new package boundaries.
- Consumers install `juvo/as-processor` first, then add only the adapter packages they need.
