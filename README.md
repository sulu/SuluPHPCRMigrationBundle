<h1 align="center">SuluPhpcrMigrationBundle</h1>

<p align="center">
    <a href="https://sulu.io/" target="_blank">
        <img width="30%" src="https://sulu.io/uploads/media/800x/00/230-Official%20Bundle%20Seal.svg?v=2-6&inline=1" alt="Official Sulu Bundle Badge">
    </a>
</p>

## Upgrading Data from Sulu 2.6 to Sulu 3.0

> ![Note]
> The upgrade from Sulu 2.6 to Sulu 3.0 is a major upgrade and will require some migration steps.

You can find the full upgrade guide here: [sulu/sulu UPGRADE.md](https://github.com/sulu/sulu/blob/3.0/UPGRADE-3.x.md)

## How to install

Install the package:

```shell
composer require sulu/phpcr-migration-bundle
```

Add the new migration bundle to your `config/bundles.php`:

```diff
// config/bundles.php

return [
    // ...
+   Sulu\Bundle\PhpcrMigrationBundle\SuluPhpcrMigrationBundle::class => ['all' => true],
```

Configure the SuluPhpcrMigrationBundle in `config/packages/sulu_phpcr_migration.yaml`:

> If you are currently using Jackrabbit, use the "jackrabbit://" based DSN string.
> After the upgrade, Apache Jackrabbit is no longer used by Sulu’s new content storage and can be removed from
> your projects in most situations.

```yaml
# config/packages/sulu_phpcr_migration.yaml

sulu_phpcr_migration:
    # dbal://<dbalConnection>?workspace=<workspaceName>
    # jackrabbit://<user>:<password>@<host>:<port>/server?workspace=<workspaceName>
    #    DSN: "dbal://default?workspace=%env(PHPCR_WORKSPACE)%"
    #    DSN: "jackrabbit://admin:admin@127.0.0.1:8080/server?workspace=%env(PHPCR_WORKSPACE)%"
    DSN: "dbal://default?workspace=%env(PHPCR_WORKSPACE)%"
    target:
        dbal:
            connection: default
```

## How to use

```shell
# Update the database to the latest phpcr migration to prepare the structure
php bin/adminconsole phpcr:migrations:migrate

# Run the command to migrate for the data
php bin/adminconsole sulu:phpcr-migration:migrate
```

In case of some errors on customized code, you can try to fix it and rerun the command. The migration command can be
rerun, the existing already migrated content will be overwritten and not duplicated.

If you only want to migrate certain document types pass them as an argument (or comma separated if it's a list)

```shell
php bin/adminconsole sulu:phpcr-migration:migrate snippet
```

Allowed types are: `snippet`, `page`, `article`

### Dry-run mode

Preview a migration without writing anything to the target database:

```shell
php bin/adminconsole sulu:phpcr-migration:migrate --dry-run
```

This executes the full parse and persist pipeline against your real PHPCR
content, but every database write is intercepted in-memory. The command
collects every exception (one per document or document/locale pair),
continues past failures, and at the end prints a grouped summary plus a
JSON report you can hand off for data cleanup.

Default report path: `var/phpcr-migration/dry-run-YYYYMMDD-HHMMSS.json`.
Override it with `--report=/path/to/report.json`.

**Limitations.** Dry-run catches PHP-level validation errors (title/slug
length, missing webspace or parent, missing shadow template, etc.) but
does **not** catch database-level issues such as foreign-key violations,
unique-key collisions between two rows that would be written in the same
run, column truncation, or NOT NULL violations on columns populated only
by database defaults. Post-migration queries (permission contexts, access
controls, automation tasks) are skipped in dry-run mode.

## 🛠️&nbsp; Development

### Quick Start

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run code quality checks
composer lint

# Auto-fix code style
composer fix
```

### Testing

This bundle uses functional tests with JSON baseline comparison to validate migration correctness.

See **[docs/TESTING.md](docs/TESTING.md)** for comprehensive documentation:
- Test infrastructure and architecture
- How baseline comparison works
- Adding new test content to Sulu 2.6
- Updating Sulu 3.0 target schema
- Regenerating baselines

## ❤️&nbsp; Support and Contributions

The Sulu content management system is a **community-driven open source project** backed by various partner companies.
We are committed to a fully transparent development process and **highly appreciate any contributions**.

In case you have questions, we are happy to welcome you in our official [Slack channel](https://sulu.io/services-and-support).
If you found a bug or miss a specific feature, feel free to **file a new issue** with a respective title and description
on the [sulu/SuluPHPCRMigrationBundle](https://github.com/sulu/SuluPHPCRMigrationBundle) repository.


## 📘&nbsp; License

The Sulu content management system is released under the under terms of the [MIT License](LICENSE).
