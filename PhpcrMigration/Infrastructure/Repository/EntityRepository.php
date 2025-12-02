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
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Sequence;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

class EntityRepository implements EntityRepositoryInterface, ResetInterface
{
    /**
     * @var Sequence[]|null
     */
    private ?array $sequences = null;

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

        if ($exists) {
            $this->connection->update(
                $tableName,
                $data,
                $where,
                $types
            );
        } else {
            // If this database is using sequences like PostgreSQL and we're doing an insert, we need to handle the ID
            if (!\array_key_exists('id', $data)) {
                $nextIdValue = $this->getNextIdValue($tableName);
                if (null !== $nextIdValue) {
                    $data['id'] = $nextIdValue;
                }
            }

            $this->connection->insert(
                $tableName,
                $data,
                $types
            );
        }
    }

    public function findOneBy(string $tableName, array $where): ?array
    {
        [$conditions, $params] = $this->parseWhereParts($where);

        $query = 'SELECT * FROM ' . $tableName . ' WHERE ' . \implode(' AND ', $conditions);
        $result = $this->connection->fetchAssociative($query, $params);

        return $result ?: null;
    }

    public function findBy(string $tableName, array $where): array
    {
        [$conditions, $params] = $this->parseWhereParts($where);

        $query = 'SELECT * FROM ' . $tableName . ' WHERE ' . \implode(' AND ', $conditions);
        $result = $this->connection->fetchAllAssociative($query, $params);

        return $result;
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

    public function tableExists(string $tableName): bool
    {
        // Bypass Doctrine's schema_filter which excludes ro_routes_old
        $databaseName = $this->connection->getDatabase();
        $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?';

        return false !== $this->connection->fetchOne($sql, [$databaseName, $tableName]);
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

    /**
     * Creates a new root node.
     */
    public function createOrUpdateRootNode(array $data, string $tableName, array $types, array $where = []): void
    {
        $exists = [] !== $where && $this->exists($tableName, $where);

        if ($exists) {
            $this->connection->update(
                $tableName,
                $data,
                $where,
                $types
            );
        } else {
            // Find the max right value to place our new root after existing roots
            /** @var false|int|null $maxRgtValue */
            $maxRgtValue = $this->connection->fetchOne("SELECT MAX(rgt) FROM $tableName");
            $maxRgt = false !== $maxRgtValue && null !== $maxRgtValue ? (int) $maxRgtValue : 0;

            // Set tree values for new root
            $data['lft'] = $maxRgt + 1;
            $data['rgt'] = $maxRgt + 2;
            $data['depth'] = 0;

            $this->connection->insert($tableName, $data, $types);
        }
    }

    /**
     * Adds a child node to a parent or updates it if it already exists.
     */
    public function addOrUpdateChildNode(array $data, string $tableName, array $types, string $parentId, array $where = []): void
    {
        $exists = [] !== $where && $this->exists($tableName, $where);

        if ($exists) {
            $this->connection->update(
                $tableName,
                $data,
                $where,
                $types
            );

            return;
        }

        /** @var false|null|array{
         *     lft: int,
         *     rgt: int,
         *     depth: int
         * } $parent
         */
        $parent = $this->connection->fetchAssociative(
            "SELECT lft, rgt, depth FROM $tableName WHERE uuid = ?",
            [$parentId]
        );

        if (!$parent) {
            throw new \RuntimeException("Parent node $parentId not found");
        }

        $parentRgt = (int) $parent['rgt'];
        $parentDepth = (int) $parent['depth'];

        // Make space for new node
        $this->connection->executeStatement(
            "UPDATE $tableName SET rgt = rgt + 2 WHERE rgt >= ?",
            [$parentRgt]
        );

        $this->connection->executeStatement(
            "UPDATE $tableName SET lft = lft + 2 WHERE lft > ?",
            [$parentRgt]
        );

        // Insert new node
        $data['lft'] = $parentRgt;
        $data['rgt'] = $parentRgt + 1;
        $data['depth'] = $parentDepth + 1;

        $this->connection->insert($tableName, $data, $types);
    }

    private function getNextIdValue(string $tableName): ?int
    {
        $result = null;

        $platform = $this->connection->getDatabasePlatform();
        $sequences = $this->getSequences();
        foreach ($sequences as $sequence) {
            $sequenceName = $sequence->getName();
            if (\str_contains($sequenceName, $tableName)) {
                /** @var int|null|false $result */
                $result = $this->connection->fetchOne(
                    $platform->getSequenceNextValSQL($sequenceName)
                );

                if (false === $result || null === $result) {
                    throw new \RuntimeException('Failed to get next ID value from sequence' . $sequenceName);
                }

                break;
            }
        }

        return $result;
    }

    /**
     * @return Sequence[]
     */
    private function getSequences(): array
    {
        if (null !== $this->sequences) {
            return $this->sequences;
        }

        $sequences = [];
        try {
            $sequences = $this->connection->createSchemaManager()->listSequences();
        } catch (Exception) {
            // @ignoreException
        }

        return $this->sequences = $sequences;
    }

    public function reset(): void
    {
        $this->sequences = null;
    }
}
