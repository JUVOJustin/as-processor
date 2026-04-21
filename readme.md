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

## Releases

This repository uses `symplify/monorepo-builder` for monorepo package management and `danharrin/monorepo-split-github-action` to publish the adapter packages to split repositories.

Available monorepo management commands:

```bash
composer run monorepo:validate
composer run monorepo:localize
composer run monorepo:propagate
composer run monorepo:package-alias
```

Recommended public release model:

- publish `juvo/as-processor` from this repository
- publish adapter packages from split repositories
- register the source repo and each adapter split repo on Packagist

Release tags:

```text
v1.2.0          # core package from this repository
csv-v1.2.0      # CSV adapter split repo
excel-v1.2.0    # Excel adapter split repo
json-v1.2.0     # JSON adapter split repo
api-v1.2.0      # API adapter split repo
```

The repository includes two release workflows:

- `.github/workflows/split-packages.yml`: syncs adapter split repositories on `main` and publishes adapter tags
- `.github/workflows/release-core.yml`: notifies Packagist when a core `v*` tag is pushed

Required GitHub secret:

- `SPLIT_REPO_TOKEN`: token with push access to the adapter split repositories

Required GitHub repository variables:

- `SPLIT_REPO_OWNER`
- `SPLIT_REPO_CSV`
- `SPLIT_REPO_EXCEL`
- `SPLIT_REPO_JSON`
- `SPLIT_REPO_API`

Optional Packagist update variables:

- `PACKAGIST_UPDATE_URL_CORE`
- `PACKAGIST_UPDATE_URL_CSV`
- `PACKAGIST_UPDATE_URL_EXCEL`
- `PACKAGIST_UPDATE_URL_JSON`
- `PACKAGIST_UPDATE_URL_API`

The split workflow assumes the root package is released from this repository, while only the adapter packages are mirrored to split repositories.

Current caveat:

- internal package constraints remain `@dev` on the main branch for monorepo development, so the release process still needs a follow-up step if you want adapter split repos to publish stable root-package constraints automatically
