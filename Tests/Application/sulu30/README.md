# Sulu 3.0 Schema Extractor

Extracts Sulu 3.0 schema for migration testing. **Not required** for running tests.

## When to Update

- Sulu 3.0 schema changes
- Migration tests fail due to missing columns
- Adding new content types

## Setup

1. Install:
   ```bash
   composer install
   ```
   
2. Create schema:
   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:schema:create
   ```
or update schema:
    ```bash
    php bin/console doctrine:schema:update --complete --force
    ```

## Export Schema

```bash
# Dump schema only (no data) — from bundle root
mysqldump -h 127.0.0.1 -u root -p --no-data migration_sulu_30 > Tests/Resources/fixtures/sulu30_schema.sql
```

The test fixture (`test_fixture.sql`) is auto-generated at test runtime by `TestFixtureBuilder` when source files change.
