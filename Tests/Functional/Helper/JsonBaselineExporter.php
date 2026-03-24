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
        $primaryKeyResult = $this->connection->fetchAllAssociative(
            "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'"
        );
        $primaryKeyColumns = \array_column($primaryKeyResult, 'Column_name');
        $orderBy = [] === $primaryKeyColumns ? '' : ' ORDER BY ' . \implode(', ', $primaryKeyColumns);

        $rows = $this->connection->fetchAllAssociative("SELECT * FROM `{$table}`{$orderBy}");

        $normalizedRows = \array_map(
            fn (array $row): array => $this->sortKeys(
                \array_map(
                    fn (mixed $value): mixed => $this->normalizeValue($value),
                    $row
                )
            ),
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
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->connection->getDatabase()]
        );

        $tables = [];
        foreach ($rows as $row) {
            $tableName = $row['TABLE_NAME'];
            if (!\is_string($tableName)) {
                continue;
            }

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
                return $decoded;
            }
        }

        return $value;
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
