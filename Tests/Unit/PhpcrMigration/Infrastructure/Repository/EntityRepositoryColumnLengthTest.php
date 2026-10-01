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
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository\EntityRepository;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryColumnLengthTest extends TestCase
{
    use ProphecyTrait;

    public function testGetColumnLengthReturnsTheLengthOfTheColumn(): void
    {
        $column = new Column('slug', Type::getType(Types::STRING), ['length' => 255]);

        $this->assertSame(255, $this->createRepository($column)->getColumnLength('ro_routes', 'slug'));
    }

    public function testGetColumnLengthFailsForAColumnWithoutLength(): void
    {
        $column = new Column('slug', Type::getType(Types::TEXT));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('ro_routes.slug');

        $this->createRepository($column)->getColumnLength('ro_routes', 'slug');
    }

    private function createRepository(Column $column): EntityRepository
    {
        $schemaManager = $this->prophesize(AbstractSchemaManager::class);
        $schemaManager->introspectTable('ro_routes')->willReturn(new Table('ro_routes', [$column]));

        $connection = $this->prophesize(Connection::class);
        $connection->createSchemaManager()->willReturn($schemaManager->reveal());

        return new EntityRepository($connection->reveal());
    }
}
