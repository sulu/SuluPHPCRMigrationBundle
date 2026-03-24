# Sulu 2.6 Test Application

Creates test fixtures for migration testing. **Not required** for running tests.

## When to Use

- Add new test content (pages, snippets, articles)
- Reproduce edge cases
- Update test fixtures

## Setup

1. Install:
   ```bash
   composer install
   ```

2. Build:
   ```bash
   php bin/console doctrine:database:create
   php bin/adminconsole sulu:build dev
   ```

3. Start server:
   ```bash
   symfony serve
   ```

## Fixture Management

### Import existing fixture
```bash
composer import-fixture
```
Loads `../../Resources/fixtures/sulu26_dump.sql` into the database.

### Export current database
```bash
composer export-fixture
```
Saves database to `../../Resources/fixtures/sulu26_dump.sql`.

## Complete Workflow

```bash
# 1. Import existing fixture
composer import-fixture

# 2. Start Sulu admin and modify content
symfony serve

# 3. Export your changes
composer export-fixture

# 4. Run tests to regenerate baselines (from bundle root)
cd ../../..
rm Tests/Resources/baselines/*.json
composer test
```
