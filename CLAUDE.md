# AS Processor Development Guide

## Build/Lint/Test Commands
```bash
# Install dependencies
composer install

# Run tests
composer test:unit                    # Run all unit tests
vendor/bin/phpunit tests/HelperTest  # Run single test file

# Code quality
composer phpstan                     # Run PHPStan analysis
composer phpcs                       # Check coding standards
composer phpcbf                      # Auto-fix coding standards

# DDEV environment
ddev start                          # Start local environment
ddev composer <command>             # Run composer in container
```

## Code Style Guidelines
- **PHP 8.1+** with strict typing and type declarations
- **WordPress Coding Standards** enforced via PHPCS
- **PSR-4 autoloading**: `juvo\AS_Processor\` namespace maps to `src/`
- **Class naming**: PascalCase with underscores (e.g., `Sync_Data`, `Chunk_DB`)
- **File naming**: Match class names (e.g., `Sync_Data.php`)
- **Imports**: Group by type (PHP core, WordPress, vendor, project)
- **Error handling**: Use WP_Exception for service logic, WP_Error for APIs
- **Documentation**: PHPDoc blocks required for all classes/methods
- **Early returns**: Use guard clauses to reduce nesting
- **No trailing commas** in arrays or function calls