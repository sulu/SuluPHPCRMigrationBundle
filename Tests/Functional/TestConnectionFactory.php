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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Fixture\TestFixtureBuilder;

final class TestConnectionFactory
{
    private const FIXTURES_DIR = __DIR__ . '/../Resources/fixtures';

    private static bool $fixtureLoaded = false;

    public static function loadFixture(Connection $connection): void
    {
        if (self::$fixtureLoaded) {
            return;
        }

        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            // For PostgreSQL, pgloader has already set up the database
            self::$fixtureLoaded = true;

            return;
        }

        $builder = new TestFixtureBuilder(self::FIXTURES_DIR);

        if ($builder->needsRebuild()) {
            $builder->build();
        }

        $fixturesPath = $builder->getOutputPath();
        if (!\file_exists($fixturesPath)) {
            throw new \RuntimeException("Test fixture not found: {$fixturesPath}\nEnsure sulu26_dump.sql and sulu30_schema.sql exist in Tests/Resources/fixtures/");
        }

        $params = $connection->getParams();
        $databaseName = $params['dbname'] ?? throw new \RuntimeException('Database name not found in connection params');

        $connection->executeStatement("DROP DATABASE IF EXISTS `{$databaseName}`");
        $connection->executeStatement("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->executeStatement("USE `{$databaseName}`");

        $sql = \file_get_contents($fixturesPath);
        if (false === $sql) {
            throw new \RuntimeException('Failed to read fixture file');
        }

        $connection->executeStatement($sql);
        self::$fixtureLoaded = true;
    }
}
