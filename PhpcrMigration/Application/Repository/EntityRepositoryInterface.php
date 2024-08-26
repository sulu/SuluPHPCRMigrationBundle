<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository;

interface EntityRepositoryInterface
{
    public function beginTransaction(): void;

    public function commit(): void;

    /**
     * @param mixed[] $data
     * @param array<string, string> $types
     * @param array<string, mixed> $where
     */
    public function insertOrUpdate(array $data, string $tableName, array $types, array $where = []): void;

    /**
     * @param mixed[] $where
     *
     * @return mixed[]|null
     */
    public function findBy(string $tableName, array $where): ?array;

    /**
     * @param mixed[] $where
     */
    public function exists(string $tableName, array $where): bool;

    /**
     * @param mixed[] $where
     */
    public function removeBy(string $tableName, array $where): int|string;
}
