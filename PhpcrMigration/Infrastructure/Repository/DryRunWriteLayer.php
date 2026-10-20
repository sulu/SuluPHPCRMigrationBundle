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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\TextType;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\UniqueKeyViolationException;

/**
 * Keeps the writes of a dry run in memory and merges them with the rows of the database, so a lookup
 * finds what the real run would find at the same point.
 *
 * A database row that is updated or removed moves into the write layer, the database itself stays untouched.
 *
 * A row keeps neither text, JSON nor blob columns that no index covers, because the content of a
 * whole migration would otherwise stay in memory until the command ends. Nothing reads these
 * columns back, and a lookup by one of them throws.
 */
class DryRunWriteLayer
{
    /**
     * @var array<string, array<string, array<string, mixed>>> rows by table and row key
     */
    private array $rows = [];

    /**
     * @var array<string, array<string, true>> keys of the database rows a write layer row replaces or that were removed
     */
    private array $replaced = [];

    /**
     * Row keys by table, column and value, built the first time a lookup uses the column.
     *
     * @var array<string, array<string, array<string, array<string, true>>>>
     */
    private array $indexes = [];

    /**
     * @var array<string, int>
     */
    private array $lastSyntheticIds = [];

    private int $lastNewRowKey = 0;

    /**
     * @var array<string, array{hasId: bool, primary: list<string>, unique: list<array{name: string, columns: list<string>}>, unstored: list<string>}>
     */
    private array $schemas = [];

    private readonly bool $caseInsensitive;

    /**
     * @param \Closure(string, array<string, mixed>): array<array<string, mixed>> $selectRows reads the rows of the database
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure $selectRows,
    ) {
        // MySQL compares strings case-insensitively with its default collations
        $this->caseInsensitive = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function isTouched(string $table): bool
    {
        return isset($this->rows[$table]) || isset($this->replaced[$table]);
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return list<array<string, mixed>>
     */
    public function find(string $table, array $where): array
    {
        return \array_values($this->match($table, $where));
    }

    /**
     * Updates the matching rows or inserts a new one when none matches.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param array<string, mixed> $insertOnly columns set only on an insert
     */
    public function insertOrUpdate(string $table, array $data, array $where, array $insertOnly = []): void
    {
        $matches = [] === $where ? [] : $this->match($table, $where);

        if ([] === $matches) {
            $this->insert($table, $data + $insertOnly);

            return;
        }

        // an UPDATE statement fails as a whole, so a violation must not keep the rows changed before it
        $previousStates = [];
        try {
            foreach ($matches as $key => $row) {
                $previousStates[$key] = [$this->rows[$table][$key] ?? null, isset($this->replaced[$table][$key])];
                $this->update($table, $key, $row, $data);
            }
        } catch (UniqueKeyViolationException $exception) {
            foreach (\array_reverse($previousStates, true) as $key => [$previousRow, $wasReplaced]) {
                null === $previousRow ? $this->unsetRow($table, $key) : $this->setRow($table, $key, $previousRow);

                if (!$wasReplaced) {
                    unset($this->replaced[$table][$key]);
                }
            }

            if ([] === ($this->replaced[$table] ?? null)) {
                unset($this->replaced[$table]);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return int the number of removed rows
     */
    public function remove(string $table, array $where): int
    {
        $matches = $this->match($table, $where);

        foreach (\array_keys($matches) as $key) {
            $this->unsetRow($table, $key);

            if (\str_starts_with($key, 'db:')) {
                $this->replaced[$table][$key] = true;
            }
        }

        return \count($matches);
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return array<string, array<string, mixed>> rows by row key
     */
    private function match(string $table, array $where): array
    {
        $unstoredColumns = $this->getSchema($table)['unstored'];
        foreach (\array_keys($where) as $column) {
            if (\in_array(\strtolower($column), $unstoredColumns, true)) {
                throw new \LogicException(\sprintf('The write layer does not keep the column "%s.%s", so it cannot look up by it.', $table, $column));
            }
        }

        $matches = [];
        $hasPrimaryKey = [] !== $this->getSchema($table)['primary'];
        $occurrences = [];

        foreach (($this->selectRows)($table, $where) as $row) {
            $key = $this->getDatabaseRowKey($table, $row);
            if (!$hasPrimaryKey) {
                // identical rows have no key of their own, and they match a lookup all together or not at all
                $key .= '#' . ($occurrences[$key] = ($occurrences[$key] ?? -1) + 1);
            }

            if (!isset($this->replaced[$table][$key])) {
                $matches[$key] = $row;
            }
        }

        foreach ($this->getCandidateKeys($table, $where) as $key) {
            $row = $this->rows[$table][$key];
            if ($this->matches($row, $where)) {
                $matches[$key] = $row;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return list<string>
     */
    private function getCandidateKeys(string $table, array $where): array
    {
        $candidates = null;
        foreach ($where as $column => $expected) {
            $keys = $this->getIndex($table, $column)[$this->getIndexKey($expected)] ?? [];
            if (null === $candidates || \count($keys) < \count($candidates)) {
                $candidates = $keys;
            }
        }

        return \array_map('strval', \array_keys($candidates ?? $this->rows[$table] ?? []));
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function getIndex(string $table, string $column): array
    {
        $column = \strtolower($column);
        if (!isset($this->indexes[$table][$column])) {
            $this->indexes[$table][$column] = [];
            foreach ($this->rows[$table] ?? [] as $key => $row) {
                $this->indexes[$table][$column][$this->getIndexKey($this->getValue($row, $column))][$key] = true;
            }
        }

        return $this->indexes[$table][$column];
    }

    private function getIndexKey(mixed $value): string
    {
        return null === $value ? "\0null" : $this->normalizeValue($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function setRow(string $table, string $key, array $row): void
    {
        $this->unsetRow($table, $key);

        $unstoredColumns = $this->getSchema($table)['unstored'];
        foreach (\array_keys($row) as $column) {
            if (\in_array(\strtolower((string) $column), $unstoredColumns, true)) {
                unset($row[$column]);
            }
        }

        $this->rows[$table][$key] = $row;
        foreach (\array_keys($this->indexes[$table] ?? []) as $column) {
            $this->indexes[$table][$column][$this->getIndexKey($this->getValue($row, $column))][$key] = true;
        }
    }

    private function unsetRow(string $table, string $key): void
    {
        if (!isset($this->rows[$table][$key])) {
            return;
        }

        foreach (\array_keys($this->indexes[$table] ?? []) as $column) {
            unset($this->indexes[$table][$column][$this->getIndexKey($this->getValue($this->rows[$table][$key], $column))][$key]);
        }

        unset($this->rows[$table][$key]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(string $table, array $data): void
    {
        $ignoredColumns = [];
        if ($this->getSchema($table)['hasId'] && null === $this->getValue($data, 'id')) {
            // negative, so it never collides with a row of the database and consumes no sequence
            $data['id'] = $this->lastSyntheticIds[$table] = ($this->lastSyntheticIds[$table] ?? 0) - 1;
            $ignoredColumns = ['id'];
        }

        $this->assertUnique($table, $data, null, null, $ignoredColumns);

        $this->setRow($table, 'new:' . ++$this->lastNewRowKey, $data);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     */
    private function update(string $table, string $key, array $row, array $data): void
    {
        $changedColumns = [];
        foreach ($data as $column => $value) {
            $existingColumn = $this->findColumn($row, $column) ?? $column;
            if (!$this->equals($row[$existingColumn] ?? null, $value)) {
                $changedColumns[] = $existingColumn;
            }
            $row[$existingColumn] = $value;
        }

        if ([] !== $changedColumns) {
            $this->assertUnique($table, $row, $key, $changedColumns);
        }

        if (\str_starts_with($key, 'db:')) {
            $this->replaced[$table][$key] = true;
        }

        $this->setRow($table, $key, $row);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>|null $changedColumns only the keys containing one of these columns are checked
     * @param list<string> $ignoredColumns keys containing one of these columns are not checked
     */
    private function assertUnique(string $table, array $row, ?string $ownKey, ?array $changedColumns, array $ignoredColumns = []): void
    {
        foreach ($this->getSchema($table)['unique'] as $key) {
            $columns = $key['columns'];
            if ($this->containsColumn($columns, $ignoredColumns)
                || (null !== $changedColumns && !$this->containsColumn($columns, $changedColumns))
            ) {
                continue;
            }

            $where = [];
            foreach ($columns as $column) {
                $value = $this->getValue($row, $column);
                if (null === $value) {
                    // a NULL value never collides
                    continue 2;
                }
                $where[$column] = $value;
            }

            foreach (\array_keys($this->match($table, $where)) as $matchKey) {
                if ($matchKey !== $ownKey) {
                    throw new UniqueKeyViolationException(
                        $table,
                        $key['name'],
                        \array_values(\array_map(fn (mixed $value): string => $this->normalizeValue($value, false), $where)),
                    );
                }
            }
        }
    }

    /**
     * @param list<string> $columns
     * @param list<string> $searchedColumns
     */
    private function containsColumn(array $columns, array $searchedColumns): bool
    {
        $searchedColumns = \array_map('strtolower', $searchedColumns);
        foreach ($columns as $column) {
            if (\in_array(\strtolower($column), $searchedColumns, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $column => $expected) {
            if (!$this->equals($this->getValue($row, $column), $expected)) {
                return false;
            }
        }

        return true;
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if (null === $actual || null === $expected) {
            return null === $actual && null === $expected;
        }

        return $this->normalizeValue($actual) === $this->normalizeValue($expected);
    }

    private function normalizeValue(mixed $value, bool $applyCollation = true): string
    {
        $string = match (true) {
            \is_bool($value) => $value ? '1' : '0',
            \is_scalar($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) \json_encode($value),
        };

        return $applyCollation && $this->caseInsensitive ? \mb_strtolower($string) : $string;
    }

    /**
     * Columns are case-insensitive, and PostgreSQL returns them in lower case.
     *
     * @param array<string, mixed> $row
     */
    private function getValue(array $row, string $column): mixed
    {
        $existingColumn = $this->findColumn($row, $column);

        return null === $existingColumn ? null : $row[$existingColumn];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function findColumn(array $row, string $column): ?string
    {
        if (\array_key_exists($column, $row)) {
            return $column;
        }

        foreach (\array_keys($row) as $existingColumn) {
            if (0 === \strcasecmp((string) $existingColumn, $column)) {
                return (string) $existingColumn;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getDatabaseRowKey(string $table, array $row): string
    {
        $columns = $this->getSchema($table)['primary'];
        $values = [];
        foreach ([] === $columns ? \array_keys($row) : $columns as $column) {
            $values[] = $this->normalizeValue($this->getValue($row, (string) $column), false);
        }

        return 'db:' . \sha1(\implode("\0", $values));
    }

    /**
     * @return array{hasId: bool, primary: list<string>, unique: list<array{name: string, columns: list<string>}>, unstored: list<string>}
     */
    private function getSchema(string $table): array
    {
        if (isset($this->schemas[$table])) {
            return $this->schemas[$table];
        }

        try {
            $definition = $this->connection->createSchemaManager()->introspectTable($table);
        } catch (TableDoesNotExist) {
            return $this->schemas[$table] = ['hasId' => false, 'primary' => [], 'unique' => [], 'unstored' => []];
        }

        $primary = [];
        $unique = [];
        $indexed = [];
        foreach ($definition->getIndexes() as $index) {
            foreach ($index->getColumns() as $column) {
                $indexed[\strtolower($column)] = true;
            }

            if ($index->isPrimary()) {
                $primary = $index->getColumns();
            }

            if ($index->isUnique()) {
                $unique[] = ['name' => $index->isPrimary() ? 'PRIMARY' : $index->getName(), 'columns' => $index->getColumns()];
            }
        }

        $unstored = [];
        foreach ($definition->getColumns() as $column) {
            $type = $column->getType();
            $name = \strtolower($column->getName());
            if (!isset($indexed[$name]) && ($type instanceof TextType || $type instanceof JsonType || $type instanceof BlobType)) {
                $unstored[] = $name;
            }
        }

        return $this->schemas[$table] = [
            'hasId' => $definition->hasColumn('id'),
            'primary' => $primary,
            'unique' => $unique,
            'unstored' => $unstored,
        ];
    }
}
