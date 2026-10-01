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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Infrastructure\Repository;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository\EntityRepository;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryExistsTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideReferencedTables(): iterable
    {
        yield 'media' => ['me_media'];
        yield 'contacts' => ['co_contacts'];
        yield 'categories' => ['ca_categories'];
        yield 'tags' => ['ta_tags'];
    }

    #[DataProvider('provideReferencedTables')]
    public function testExistsQueriesTheSameRowOnlyOnce(string $table): void
    {
        $connection = $this->prophesize(Connection::class);
        $connection->fetchOne(Argument::cetera())->willReturn('1');

        $repository = new EntityRepository($connection->reveal());

        $this->assertTrue($repository->exists($table, ['id' => 5]));
        $this->assertTrue($repository->exists($table, ['id' => 5]));

        $connection->fetchOne(Argument::cetera())->shouldHaveBeenCalledOnce();
    }
}
