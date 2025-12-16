# Testing Documentation

## Overview

This bundle uses a comprehensive functional testing approach with JSON baseline comparison to ensure migration correctness across Sulu versions.

## Test Infrastructure

### Architecture

```
Tests/
├── Application/
│   ├── sulu26/              # Optional: Sulu 2.6 for creating test content
│   └── sulu30/              # Optional: Sulu 3.0 for schema extraction
├── Functional/
│   ├── Baseline/
│   │   └── BaselineComparisonTest.php  # Main test suite
│   ├── Fixture/
│   │   └── TestFixtureBuilder.php      # Combines Sulu 2.6 + 3.0 fixtures
│   └── Helper/
│       └── JsonBaselineExporter.php    # Exports migrated tables to JSON
└── Resources/
    ├── fixtures/
    │   ├── sulu26_dump.sql      # Sulu 2.6 database dump (source data)
    │   ├── sulu30_schema.sql    # Sulu 3.0 schema (target tables, no data)
    │   └── test_fixture.sql     # Combined fixture (auto-generated)
    └── baselines/
        └── *.json               # Expected migration output
```

### Data Flow

```
┌─────────────────────┐     ┌─────────────────────┐
│  sulu26_dump.sql    │     │  sulu30_schema.sql  │
│  (Sulu 2.6 data)    │     │  (Sulu 3.0 schema)  │
│  - PHPCR tables     │     │  - pa_* tables      │
│  - permissions etc  │     │  - sn_*, ar_*, cu_* │
└─────────┬───────────┘     └──────────┬──────────┘
          │                            │
          └──────────┬─────────────────┘
                     ▼
          ┌─────────────────────┐
          │  TestFixtureBuilder │
          │  (merges at runtime)│
          └──────────┬──────────┘
                     ▼
          ┌─────────────────────┐
          │  test_fixture.sql   │
          │  - All 2.6 data     │
          │  - Empty 3.0 tables │
          └──────────┬──────────┘
                     ▼
     ┌───────────────┴───────────────┐
     │                               │
     ▼                               ▼
┌─────────┐                   ┌─────────────┐
│  MySQL  │                   │  pgloader   │
└────┬────┘                   │ MySQL → PG  │
     │                        └──────┬──────┘
     │                               ▼
     │                        ┌────────────┐
     │                        │ PostgreSQL │
     │                        └─────┬──────┘
     │                              │
     └──────────────┬───────────────┘
                    ▼
          ┌─────────────────────┐
          │  Run Migration      │
          │  PHPCR → 3.0 tables │
          └──────────┬──────────┘
                     ▼
          ┌─────────────────────┐
          │  Compare baselines  │
          └─────────────────────┘
```

### How It Works

1. **Fixture Building** (`TestFixtureBuilder`)
   - Combines Sulu 2.6 data with Sulu 3.0 empty schema
   - Automatically rebuilds when source files change
   - Creates migration-ready database structure

2. **Migration Execution** (`BaselineComparisonTest`)
   - Loads combined fixture into test database
   - Runs full migration (pages, snippets, articles, custom URLs, snippet areas)
   - Exports all migrated tables to JSON

3. **Baseline Comparison**
   - First run: Generates baseline files if missing
   - Subsequent runs: Compares migrated data against baselines
   - Automatically excludes non-deterministic fields (`_id`, `id`, `uuid`, `changed`)
   - Normalizes data for cross-database comparison (column casing, booleans, timestamps)
   - Sorts rows for deterministic comparison

## Running Tests

### Quick Start

```bash
# Run all tests
composer test

# Run only functional tests
vendor/bin/phpunit --testsuite=Functional

# Run specific test group
vendor/bin/phpunit --group optional
```

### Environment Variables

Tests require MySQL:

```bash
export DATABASE_HOST=127.0.0.1
export DATABASE_USER=root
export DATABASE_PASSWORD=ChangeMe
export DATABASE_NAME=sulu_migration_test
```

### Running PostgreSQL Tests Locally

PostgreSQL tests ensure migration produces identical results on both databases. Use docker-compose:

```bash
cd Tests/Application
docker compose up -d mysql postgres

# Load fixture into MySQL
mysql -h 127.0.0.1 -u root -proot sulu_migration_test < ../Resources/fixtures/test_fixture.sql

# Migrate to PostgreSQL using pgloader
pgloader ../Resources/pgloader.load

# Run tests against PostgreSQL
cd ../..
DATABASE_DRIVER=pdo_pgsql DATABASE_PORT=5432 DATABASE_USER=postgres DATABASE_PASSWORD=postgres vendor/bin/phpunit
```

**Why both MySQL and PostgreSQL?**
- MySQL is the source of the fixture data (Sulu 2.6 dump)
- pgloader converts MySQL to PostgreSQL (schema + data)
- Tests run against PostgreSQL to verify cross-database compatibility
- Same JSON baselines validate both databases produce identical results

## Updating Test Data

### Adding New Test Content

The `sulu26` and `sulu30` skeleton applications are **optional** and only needed when creating new test content or updating schemas.

#### 1. Set Up Sulu 2.6 Test Environment

```bash
cd Tests/Application/sulu26
composer install

# Configure database
cat > .env.local << 'EOF'
DATABASE_URL="mysql://root:ChangeMe@127.0.0.1:3306/migration_sulu_26?serverVersion=8.0"
EOF

# Build Sulu
php bin/console doctrine:database:create
php bin/adminconsole sulu:build dev

# Start server
symfony serve
```

#### 2. Create Test Content

1. Access Sulu admin at `http://127.0.0.1:8000/admin`
2. Create pages, snippets, articles, custom URLs
3. Add edge cases (nested blocks, localizations, etc.)

#### 3. Export and Import Workflow

```bash
# Export current database state
cd Tests/Application/sulu26
composer export-fixture

# This creates: Tests/Resources/fixtures/sulu26_dump.sql

# Import later to continue editing
composer import-fixture
```

#### 4. Regenerate Baselines

From bundle root:

```bash
# Remove existing baselines
rm Tests/Resources/baselines/*.json

# Run tests to generate new baselines
composer test

# Run tests again to validate
composer test
```

## Updating Sulu 3.0 Schema

When Sulu 3.0 introduces schema changes:

```bash
cd Tests/Application/sulu30
composer install

# Configure database
cat > .env.local << 'EOF'
DATABASE_URL="mysql://root:ChangeMe@127.0.0.1:3306/sulu30_schema?serverVersion=8.0"
EOF

# Create schema
php bin/console doctrine:schema:create

# Export schema (no data)
mysqldump -u root -p --no-data sulu30_schema > ../../Resources/fixtures/sulu30_schema.sql

# Return to bundle root and regenerate fixtures
cd ../../..
rm Tests/Resources/fixtures/test_fixture.sql
composer test
```

## Understanding Baseline Comparison

### Excluded Fields

These fields are automatically excluded from comparison:

- `_id`: Internal identifiers
- `id`: Auto-increment IDs (differ between databases due to sequence handling)
- `uuid`: Entity UUIDs
- `changed`: Timestamps that depend on test execution time
- `*_id`: Foreign key columns (automatically excluded by suffix)

Add more fields to `BaselineComparisonTest::EXCLUDED_FIELDS` if needed.

### Cross-Database Normalization

The test suite normalizes data for consistent comparison across MySQL and PostgreSQL:

- **Column names**: Lowercased (PostgreSQL returns lowercase)
- **Booleans**: Converted to integers (PostgreSQL returns true/false, MySQL returns 0/1)
- **Timestamps**: Timezone suffixes removed
- **JSON arrays**: Re-encoded with consistent spacing
- **Strings**: Trimmed (PostgreSQL may have trailing spaces)

### Test Organization

The test suite is organized into groups:

1. **Page Tables** - Pages and navigation
2. **Article Tables** - Articles
3. **Snippet Tables** - Snippets and snippet areas
4. **Custom URL Tables** - Custom URLs and routes
5. **Remaining Tables** - All other tables (categories, media, users, etc.)

### Baseline File Format

Baselines are JSON files with pretty-printing:

```json
[
    {
        "id": 1,
        "title": "Homepage",
        "locale": "en",
        "templateData": "{\"title\": \"Welcome\", \"blocks\": []}",
        "seoData": "{\"title\": \"\", \"description\": \"\"}"
    }
]
```
