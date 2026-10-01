<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Fixture;

/**
 * Builds the test fixture by merging Sulu 2.6 data with Sulu 3.0 schema.
 *
 * Input files (in fixturesDir):
 *   - sulu26_dump.sql: Full Sulu 2.6 database dump
 *   - sulu30_schema.sql: Sulu 3.0 schema (--no-data)
 *
 * Output:
 *   - test_fixture.sql: Combined fixture ready for migration testing
 */
final class TestFixtureBuilder
{
    private const SULU26_DUMP = 'sulu26_dump.sql';
    private const SULU30_SCHEMA = 'sulu30_schema.sql';
    private const OUTPUT_FILE = 'test_fixture.sql';

    /**
     * Migration target table prefixes (ContentBundle tables).
     */
    private const TARGET_PREFIXES = [
        'pa_',  // Pages
        'sn_',  // Snippets
        'ar_',  // Articles
        'cu_',  // Custom URLs
    ];

    public function __construct(
        private readonly string $fixturesDir,
    ) {
    }

    /**
     * Check if the fixture needs to be rebuilt.
     *
     * Returns true if:
     * - test_fixture.sql doesn't exist
     * - sulu26_dump.sql is newer than test_fixture.sql
     * - sulu30_schema.sql is newer than test_fixture.sql
     */
    public function needsRebuild(): bool
    {
        $outputPath = $this->getOutputPath();
        $sulu26Path = $this->getSulu26DumpPath();
        $sulu30Path = $this->getSulu30SchemaPath();

        if (!\file_exists($outputPath)) {
            return true;
        }

        if (!\file_exists($sulu26Path) || !\file_exists($sulu30Path)) {
            return false;
        }

        $outputMtime = \filemtime($outputPath);
        $sulu26Mtime = \filemtime($sulu26Path);
        $sulu30Mtime = \filemtime($sulu30Path);

        if (false === $outputMtime || false === $sulu26Mtime || false === $sulu30Mtime) {
            return true;
        }

        return $sulu26Mtime > $outputMtime || $sulu30Mtime > $outputMtime;
    }

    /**
     * @throws \RuntimeException If source files are missing or unreadable
     */
    public function build(): void
    {
        $sulu26Path = $this->getSulu26DumpPath();
        $sulu30Path = $this->getSulu30SchemaPath();
        $outputPath = $this->getOutputPath();

        $this->validateSourceFiles($sulu26Path, $sulu30Path);

        $output = [];
        $output[] = '-- Test Fixture for PhpcrMigrationBundle';
        $output[] = '-- Generated: ' . \date('Y-m-d H:i:s');
        $output[] = '-- Combines Sulu 2.6 data with Sulu 3.0 schema';
        $output[] = '';
        $output[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $output[] = '';

        $sulu26Content = $this->readFile($sulu26Path);
        $output[] = '-- SULU 2.6 DATA';
        $output[] = $sulu26Content;
        $output[] = '';

        $output[] = '-- ROUTE TABLE PREPARATION';
        $output[] = 'RENAME TABLE ro_routes TO ro_routes_old;';
        $output[] = 'CREATE TABLE ro_routes (';
        $output[] = '    id INT AUTO_INCREMENT NOT NULL,';
        $output[] = '    parent_id INT DEFAULT NULL,';
        $output[] = '    webspace VARCHAR(31) DEFAULT NULL,';
        $output[] = '    locale VARCHAR(15) NOT NULL,';
        $output[] = '    slug VARCHAR(255) NOT NULL,';
        $output[] = '    resource_key VARCHAR(32) NOT NULL,';
        $output[] = '    resource_id VARCHAR(70) NOT NULL,';
        $output[] = '    INDEX IDX_ro_routes_parent (parent_id),';
        $output[] = '    INDEX IDX_ro_routes_resource (locale, resource_key, resource_id),';
        $output[] = '    UNIQUE INDEX UNIQ_ro_routes (webspace, locale, slug),';
        $output[] = '    PRIMARY KEY(id),';
        $output[] = '    CONSTRAINT FK_ro_routes_parent FOREIGN KEY (parent_id) REFERENCES ro_routes (id) ON DELETE CASCADE';
        $output[] = ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;';
        $output[] = '';

        $sulu30Content = $this->readFile($sulu30Path);
        $targetTables = $this->findTargetTables($sulu26Content, $sulu30Content);

        $output[] = '-- SULU 3.0 TARGET TABLES';
        $output[] = '';

        foreach ($targetTables as $table) {
            $createStatement = $this->extractCreateTableStatement($table, $sulu30Content);
            if (null !== $createStatement) {
                $output[] = $createStatement;
                $output[] = '';
            }
        }

        $output[] = 'SET FOREIGN_KEY_CHECKS = 1;';

        $outputContent = \implode("\n", $output);
        \file_put_contents($outputPath, $outputContent);
    }

    public function getOutputPath(): string
    {
        return $this->fixturesDir . '/' . self::OUTPUT_FILE;
    }

    private function getSulu26DumpPath(): string
    {
        return $this->fixturesDir . '/' . self::SULU26_DUMP;
    }

    private function getSulu30SchemaPath(): string
    {
        return $this->fixturesDir . '/' . self::SULU30_SCHEMA;
    }

    private function validateSourceFiles(string $sulu26Path, string $sulu30Path): void
    {
        if (!\file_exists($sulu26Path)) {
            throw new \RuntimeException("Sulu 2.6 dump not found: {$sulu26Path}\nCreate it with: mysqldump -u root -p sulu26_test > {$sulu26Path}");
        }

        if (!\file_exists($sulu30Path)) {
            throw new \RuntimeException("Sulu 3.0 schema not found: {$sulu30Path}\nCreate it with: mysqldump -u root -p --no-data sulu30_schema > {$sulu30Path}");
        }
    }

    private function readFile(string $path): string
    {
        $content = \file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        return $content;
    }

    /**
     * Find new tables in Sulu 3.0 that match target prefixes.
     *
     * @return list<string>
     */
    private function findTargetTables(string $sulu26Content, string $sulu30Content): array
    {
        \preg_match_all('/CREATE TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sulu26Content, $matches26);
        $sulu26Tables = \array_map('strtolower', $matches26[1]);

        \preg_match_all('/CREATE TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sulu30Content, $matches30);
        $sulu30Tables = \array_map('strtolower', $matches30[1]);

        $targetTables = [];
        foreach ($sulu30Tables as $table) {
            $isNewTable = !\in_array($table, $sulu26Tables, true);
            $hasTargetPrefix = false;

            foreach (self::TARGET_PREFIXES as $prefix) {
                if (\str_starts_with($table, $prefix)) {
                    $hasTargetPrefix = true;
                    break;
                }
            }

            if ($isNewTable && $hasTargetPrefix) {
                $targetTables[] = $table;
            }
        }

        \sort($targetTables);

        return $targetTables;
    }

    private function extractCreateTableStatement(string $tableName, string $schemaContent): ?string
    {
        $pattern = '/CREATE TABLE\s+`?' . \preg_quote($tableName, '/') . '`?[^;]+;/is';
        if (\preg_match($pattern, $schemaContent, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
