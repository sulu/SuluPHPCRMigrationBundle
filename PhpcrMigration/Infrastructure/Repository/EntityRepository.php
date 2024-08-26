<?php

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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

class EntityRepository implements EntityRepositoryInterface
{
    public function __construct(
        protected Connection $connection,
    ) {
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function insertOrUpdate(array $data, string $tableName, array $types, array $where = []): void
    {
        $exists = [] !== $where && $this->exists($tableName, $where);

        match ($exists) {
            true => $this->connection->update(
                $tableName,
                $data,
                $where,
                $types
            ),
            default => $this->connection->insert(
                $tableName,
                $data,
                $types
            ),
        };
    }

    public function findBy(string $tableName, array $where): ?array
    {
        [$conditions, $params] = $this->parseWhereParts($where);

        $query = 'SELECT * FROM ' . $tableName . ' WHERE ' . \implode(' AND ', $conditions);
        $result = $this->connection->fetchAssociative($query, $params);

        return $result ?: null;
    }

    public function exists(string $tableName, array $where): bool
    {
        [$conditions, $params] = $this->parseWhereParts($where);

        $query = 'SELECT 1 FROM ' . $tableName . ' WHERE ' . \implode(' AND ', $conditions);
        $result = $this->connection->fetchOne($query, $params);

        return false !== $result;
    }

    public function removeBy(string $tableName, array $where): int|string
    {
        [$conditions, $params] = $this->parseWhereParts($where);

        $query = 'DELETE FROM ' . $tableName . ' WHERE ' . \implode(' AND ', $conditions);

        return $this->connection->executeStatement($query, $params);
    }

    /**
     * @param mixed[] $where
     *
     * @return mixed[][]
     */
    private function parseWhereParts(array $where): array
    {
        $conditions = [];
        $params = [];
        foreach ($where as $key => $value) {
            if (null === $value) {
                $conditions[] = $key . ' IS NULL';
            } else {
                $conditions[] = $key . ' = :' . $key;
                $params[$key] = $value;
            }
        }

        return [$conditions, $params];
    }
}
