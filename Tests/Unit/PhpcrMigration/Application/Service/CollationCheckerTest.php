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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\CollationChecker;

#[CoversClass(CollationChecker::class)]
final class CollationCheckerTest extends TestCase
{
    use ProphecyTrait;

    public function testSingleCollationIsNotReported(): void
    {
        $checker = $this->createChecker(new MySQLPlatform(), [
            ['table_name' => 'pa_pages', 'collation_name' => 'utf8mb4_unicode_ci'],
            ['table_name' => 'ro_routes', 'collation_name' => 'utf8mb4_unicode_ci'],
        ]);

        $this->assertSame([], $checker->findMixedCollations());
    }

    public function testMixedCollationsAreGroupedWithTheMostUsedFirst(): void
    {
        $checker = $this->createChecker(new MySQLPlatform(), [
            ['table_name' => 'ro_routes', 'collation_name' => 'utf8mb4_0900_ai_ci'],
            ['table_name' => 'pa_pages', 'collation_name' => 'utf8mb4_unicode_ci'],
            ['table_name' => 'sn_snippets', 'collation_name' => 'utf8mb4_unicode_ci'],
            ['table_name' => 'ro_routes', 'collation_name' => 'utf8mb4_unicode_ci'],
        ]);

        $this->assertSame([
            'utf8mb4_unicode_ci' => ['pa_pages', 'ro_routes', 'sn_snippets'],
            'utf8mb4_0900_ai_ci' => ['ro_routes'],
        ], $checker->findMixedCollations());
    }

    public function testMariaDbIsChecked(): void
    {
        $checker = $this->createChecker(new MariaDBPlatform(), [
            ['table_name' => 'pa_pages', 'collation_name' => 'utf8mb4_unicode_ci'],
            ['table_name' => 'ro_routes', 'collation_name' => 'utf8mb3_general_ci'],
        ]);

        $this->assertSame([
            'utf8mb3_general_ci' => ['ro_routes'],
            'utf8mb4_unicode_ci' => ['pa_pages'],
        ], $checker->findMixedCollations());
    }

    public function testOtherPlatformsAreSkipped(): void
    {
        $connection = $this->prophesize(Connection::class);
        $connection->getDatabasePlatform()->willReturn(new PostgreSQLPlatform());
        $connection->fetchAllAssociative(Argument::cetera())->shouldNotBeCalled();

        $checker = new CollationChecker($connection->reveal());

        $this->assertSame([], $checker->findMixedCollations());
    }

    /**
     * @param list<array{table_name: string, collation_name: string}> $rows
     */
    private function createChecker(object $platform, array $rows): CollationChecker
    {
        $connection = $this->prophesize(Connection::class);
        $connection->getDatabasePlatform()->willReturn($platform);
        $connection->fetchAllAssociative(Argument::containingString('information_schema.COLUMNS'))->willReturn($rows);

        return new CollationChecker($connection->reveal());
    }
}
