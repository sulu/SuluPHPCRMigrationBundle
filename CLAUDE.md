# SuluPHPCRMigrationBundle

Migrates Sulu CMS content from PHPCR (v2.6) to SuluContentBundle (v3.0). Handles pages, snippets, articles, custom URLs, and snippet areas.

## Commands

```bash
composer test        # Run tests (PHPUnit)
composer lint        # All linters (PHPStan, PHP-CS-Fixer, Rector, Deptrac)
composer fix         # Auto-fix code style
composer phpstan     # Static analysis (level: max)
```

```bash
php bin/adminconsole sulu:phpcr-migration:migrate              # Full migration
php bin/adminconsole sulu:phpcr-migration:migrate page         # By type: page|snippet|article|custom_url|snippet_area
```

## Code Standards

- PHP 8.2+, `declare(strict_types=1)` in all files, MIT license header
- PHPStan level max, @Symfony code style, Deptrac layer enforcement
- Service config: **PHP** (`ContainerConfigurator`) in `Resources/config/*.php`, Symfony 8 removed the DI XML loader
- Constructor injection only, service tag prefix: `sulu_phpcr_migration.*`

## Architecture

Clean Architecture: `UserInterface/` → `Application/` → `Infrastructure/`. No reverse deps.

- **Parsers**: Implement `NodeParserInterface`, tag `sulu_phpcr_migration.node_parser`
- **Persisters**: Extend `AbstractPersister`, tag `sulu_phpcr_migration.persister` with `type` attribute

## Key Migration Patterns

- Localized PHPCR properties: `i18n-{locale}-{property}` prefix
- Block properties need length trimming via `PropertyParser::trimBlocksToLengths()`
- `seoData`/`excerptData`: JSON columns with all standard fields defaulting to `null`. Override `getSeoDataDefaults()`/`getExcerptDataDefaults()` to add custom fields. Custom PHPCR properties are auto-discovered.
- SEO booleans (`seoNoIndex`, `seoNoFollow`, `seoHideInSitemap`): separate columns
- Excerpt categories/tags/audience groups: junction tables
- Segments: per-webspace in PHPCR → single VARCHAR in 3.0 (pages use own webspace, articles use first value)
- Migrations are idempotent — content is overwritten, not duplicated

## Testing

Functional tests use MySQL with JSON baseline comparison. Connection is read from `DATABASE_URL` (defaults to `mysql://root:ChangeMe@127.0.0.1:3306/sulu_migration` via `Tests/Application/.env`). SuluTestKernel appends `_test` so the actual DB used is `sulu_migration_test`.

```bash
# Regenerate baselines after migration changes
composer test-baseline-regenerate   # Deletes old baselines and generates new ones
composer test                       # Validates baselines
```

`Tests/Application/sulu26/` and `sulu30/` are optional skeletons for creating test content or updating schema.

**CI Environment**:
- Tests run on PHP 8.2, 8.3, 8.4
- Matrix includes lowest and highest dependency versions
- MySQL 8.0 required for functional tests

### Functional Test Fixtures

Functional tests run against MySQL and PostgreSQL with JSON baselines for regression detection.

See **[docs/TESTING.md](docs/TESTING.md)** for comprehensive documentation on:
- Test data flow and architecture
- Running tests locally (MySQL and PostgreSQL)
- Adding new test content
- Updating Sulu 3.0 schema
- Baseline comparison details

### Adding PHPCR Fixture Workflow

To add new test content via code (requires MySQL running locally):

```bash
# 1. Create fixture class at Tests/Application/sulu26/src/PhpcrFixture/
#    Implement App\PhpcrFixture\PhpcrFixtureInterface (see ArticleWebspaceFixture.php for example)
#    Inject DocumentManagerInterface for creating documents

# 2. Import, apply fixtures, and export (from bundle root)
composer apply-fixture

# 3. Regenerate baselines (from bundle root)
composer test-baseline-regenerate   # Deletes old baselines and generates new ones
composer test                       # Validates baselines
```

MySQL scripts default to `root:ChangeMe@127.0.0.1`. Override: `MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PWD`.
