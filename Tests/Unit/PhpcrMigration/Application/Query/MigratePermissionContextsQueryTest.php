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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query\MigratePermissionContextsQuery;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

#[CoversClass(MigratePermissionContextsQuery::class)]
final class MigratePermissionContextsQueryTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<Connection>
     */
    private ObjectProphecy $connection;

    /**
     * @var ObjectProphecy<EntityRepositoryInterface>
     */
    private ObjectProphecy $repository;

    private MigratePermissionContextsQuery $query;

    protected function setUp(): void
    {
        $this->connection = $this->prophesize(Connection::class);
        $this->connection->fetchFirstColumn(Argument::cetera())->willReturn([]);
        $this->connection->fetchAllAssociative(Argument::cetera())->willReturn([]);
        $this->connection->executeStatement(Argument::cetera())->willReturn(0);

        $this->repository = $this->prophesize(EntityRepositoryInterface::class);

        $this->query = new MigratePermissionContextsQuery($this->repository->reveal());
    }

    public function testDefaultSnippetsContextOfAWebspaceIsRenamedToSnippetAreas(): void
    {
        $this->connection
            ->fetchAllAssociative(Argument::containingString('LIKE :pattern'), ['pattern' => 'sulu.webspaces.%.default-snippets'])
            ->willReturn([
                ['id' => 9, 'context' => 'sulu.webspaces.example.default-snippets', 'role_id' => 1],
                ['id' => 30, 'context' => 'sulu.webspaces.other.default-snippets', 'role_id' => 2],
            ]);
        $this->repository->exists('se_permissions', Argument::cetera())->willReturn(false);

        $this->query->execute($this->connection->reveal());

        $this->connection
            ->executeStatement(Argument::any(), ['newContext' => 'sulu.webspaces.example.snippet-areas', 'id' => 9])
            ->shouldHaveBeenCalledOnce();
        $this->connection
            ->executeStatement(Argument::any(), ['newContext' => 'sulu.webspaces.other.snippet-areas', 'id' => 30])
            ->shouldHaveBeenCalledOnce();
    }

    public function testRoleWithTheSnippetAreasContextIsSkipped(): void
    {
        $this->connection
            ->fetchAllAssociative(Argument::containingString('LIKE :pattern'), ['pattern' => 'sulu.webspaces.%.default-snippets'])
            ->willReturn([
                ['id' => 9, 'context' => 'sulu.webspaces.example.default-snippets', 'role_id' => 1],
            ]);
        $this->repository
            ->exists('se_permissions', ['context' => 'sulu.webspaces.example.snippet-areas', 'idRoles' => 1])
            ->willReturn(true);

        $this->query->execute($this->connection->reveal());

        $this->connection
            ->executeStatement(Argument::any(), Argument::withEntry('newContext', 'sulu.webspaces.example.snippet-areas'))
            ->shouldNotHaveBeenCalled();
    }
}
