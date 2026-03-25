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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Helper;

use Doctrine\DBAL\Connection;

class JsonBaselineExporter
{
    private const EXCLUDED_PREFIXES = [
        'phpcr_',
        'ac_',
    ];

    private const EXCLUDED_TABLES = [
        'doctrine_migration_versions',
        'migration_versions',
        'messenger_messages',
        're_references',
    ];

    /**
     * Non-deterministic fields stripped from decoded JSON values at export time
     * to keep baselines stable across runs.
     */
    private const NON_DETERMINISTIC_JSON_FIELDS = ['_id'];

    /**
     * Non-deterministic columns excluded per table at export time.
     * These are generated values (e.g. via uniqid()) that change on every run.
     *
     * @var array<string, list<string>>
     */
    private const NON_DETERMINISTIC_COLUMNS = [
        'sn_snippet_area' => ['uuid'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $outputDir,
    ) {
    }

    public function export(): int
    {
        if (!\is_dir($this->outputDir)) {
            \mkdir($this->outputDir, 0755, true);
        }

        $tables = $this->getExportTables();

        foreach ($tables as $table) {
            $this->exportTable($table);
        }

        return \count($tables);
    }

    private function exportTable(string $table): int
    {
        $schemaManager = $this->connection->createSchemaManager();
        $indexes = $schemaManager->listTableIndexes($table);
        $primaryKeyColumns = isset($indexes['primary']) ? $indexes['primary']->getColumns() : [];
        $quotedOrderByColumns = \array_map($this->connection->quoteIdentifier(...), $primaryKeyColumns);
        $orderBy = [] === $quotedOrderByColumns ? '' : ' ORDER BY ' . \implode(', ', $quotedOrderByColumns);

        $quotedTable = $this->connection->quoteIdentifier($table);
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM {$quotedTable}{$orderBy}");

        $excludedColumns = self::NON_DETERMINISTIC_COLUMNS[$table] ?? [];

        $normalizedRows = \array_map(
            function(array $row) use ($excludedColumns): array {
                foreach ($excludedColumns as $column) {
                    unset($row[$column]);
                }

                return $this->sortKeys(
                    \array_map(
                        fn (mixed $value): mixed => $this->normalizeValue($value),
                        $row
                    )
                );
            },
            $rows
        );

        $outputPath = $this->outputDir . '/' . $table . '.json';
        $json = \json_encode($normalizedRows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \RuntimeException("Failed to encode JSON for table: {$table}");
        }

        $bytesWritten = \file_put_contents($outputPath, $json);
        if (false === $bytesWritten) {
            throw new \RuntimeException("Failed to write: {$outputPath}");
        }

        return \count($rows);
    }

    /**
     * @return list<string>
     */
    private function getExportTables(): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $allTables = $schemaManager->listTableNames();
        \sort($allTables);

        $tables = [];
        foreach ($allTables as $tableName) {
            if ($this->shouldExcludeTable($tableName)) {
                continue;
            }

            $tables[] = $tableName;
        }

        return $tables;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (null === $value) {
            return '';
        }

        if (!\is_string($value) || '' === $value) {
            return $value;
        }

        if ('{' === $value[0] || '[' === $value[0]) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return $this->removeNonDeterministicFields($decoded);
            }
        }

        return $value;
    }

    /**
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private function removeNonDeterministicFields(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, self::NON_DETERMINISTIC_JSON_FIELDS, true)) {
                continue;
            }

            $result[$key] = \is_array($value) ? $this->removeNonDeterministicFields($value) : $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sortKeys(array $data): array
    {
        \ksort($data);

        foreach ($data as $key => $value) {
            if (\is_array($value) && !$this->isNumericArray($value)) {
                $data[$key] = $this->sortKeys($value);
            }
        }

        return $data;
    }

    /**
     * @param array<mixed> $array
     */
    private function isNumericArray(array $array): bool
    {
        return [] === $array || \array_keys($array) === \range(0, \count($array) - 1);
    }

    private function shouldExcludeTable(string $tableName): bool
    {
        if (\in_array($tableName, self::EXCLUDED_TABLES, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (\str_starts_with($tableName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
