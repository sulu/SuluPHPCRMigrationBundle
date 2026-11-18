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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\PhpcrMigration\Application\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\CollationChecker;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Application\Kernel;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\TestConnectionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CollationCheckerTest extends KernelTestCase
{
    private Connection $connection;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        TestConnectionFactory::loadFixture($connection);
        $this->connection = $connection;

        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped('Collations are only checked on MySQL and MariaDB.');
        }
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DROP VIEW IF EXISTS collation_check_view');
        $this->connection->executeStatement('DROP TABLE IF EXISTS collation_check_table');

        parent::tearDown();
    }

    public function testFixtureHasOneCollation(): void
    {
        $this->assertSame([], (new CollationChecker($this->connection))->findMixedCollations());
    }

    public function testBinaryColumnIsNotReported(): void
    {
        $this->connection->executeStatement('CREATE TABLE collation_check_table (data VARCHAR(10) COLLATE utf8mb4_bin) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->assertSame([], (new CollationChecker($this->connection))->findMixedCollations());
    }

    public function testUtf8mb3ColumnIsReported(): void
    {
        $this->connection->executeStatement('CREATE TABLE collation_check_table (legacy VARCHAR(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $result = (new CollationChecker($this->connection))->findMixedCollations();

        $this->assertSame(['collation_check_table'], $result['utf8mb3_unicode_ci'] ?? null);
    }

    public function testColumnWithOtherCollationIsReported(): void
    {
        $this->connection->executeStatement('CREATE TABLE collation_check_table (title VARCHAR(10) COLLATE utf8mb4_0900_ai_ci) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->connection->executeStatement('CREATE VIEW collation_check_view AS SELECT title FROM collation_check_table');

        $result = (new CollationChecker($this->connection))->findMixedCollations();

        $this->assertSame(['collation_check_table'], $result['utf8mb4_0900_ai_ci'] ?? null);
    }
}
