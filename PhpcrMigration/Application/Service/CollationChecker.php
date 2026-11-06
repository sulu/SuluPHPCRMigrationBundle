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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Detects tables and text columns of the target database that do not share the same collation.
 *
 * Sulu joins tables on text columns, which can fail with "Illegal mix of collations" when their
 * collations differ. A collation belongs to a single charset, so this also covers charsets.
 * Only MySQL and MariaDB are checked.
 */
class CollationChecker
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<string>> table names by collation, most used collation first,
     *                                     or an empty array when a single collation is used
     */
    public function findMixedCollations(): array
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.TABLE_NAME AS table_name, t.TABLE_COLLATION AS collation_name
            FROM information_schema.TABLES t
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = \'BASE TABLE\' AND t.TABLE_COLLATION IS NOT NULL
            UNION
            SELECT c.TABLE_NAME, c.COLLATION_NAME
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = \'BASE TABLE\' AND c.COLLATION_NAME IS NOT NULL',
        );

        $tablesByCollation = [];
        foreach ($rows as $row) {
            if (\is_string($row['collation_name']) && \is_string($row['table_name'])) {
                $tablesByCollation[$row['collation_name']][$row['table_name']] = true;
            }
        }

        if (\count($tablesByCollation) < 2) {
            return [];
        }

        $result = [];
        foreach ($tablesByCollation as $collation => $tables) {
            $tableNames = \array_map(strval(...), \array_keys($tables));
            \sort($tableNames);
            $result[$collation] = $tableNames;
        }

        \uksort($result, static fn (string $a, string $b): int => [\count($result[$b]), $a] <=> [\count($result[$a]), $b]);

        return $result;
    }
}
