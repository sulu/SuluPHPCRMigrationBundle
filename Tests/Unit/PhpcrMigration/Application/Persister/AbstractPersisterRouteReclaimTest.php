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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Persister;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(AbstractPersister::class)]
final class AbstractPersisterRouteReclaimTest extends TestCase
{
    use ProphecyTrait;

    private const DIMENSION_TABLE = 'pa_page_dimension_contents';
    private const CONFLICT_ROUTE_ID = 99;

    /**
     * @var ObjectProphecy<EntityRepositoryInterface>
     */
    private ObjectProphecy $repository;

    private PagePersister $persister;

    protected function setUp(): void
    {
        $this->repository = $this->prophesize(EntityRepositoryInterface::class);
        $this->persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $this->repository->reveal(),
        );
    }

    public function testReclaimDoesNotInsertBogusRowWhenConflictingRouteIsOrphaned(): void
    {
        $this->stubRouteCreation();

        // The conflicting route has no owning dimension content (skipped content or stale route),
        // so isExistingRoutePublished() cannot prove it published and the reclaim path is taken.
        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => 'other-uuid',
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn(null);

        // Nothing references the conflicting route.
        $this->repository->exists(self::DIMENSION_TABLE, ['route_id' => self::CONFLICT_ROUTE_ID])
            ->willReturn(false);

        // Regression guard: the detach must NOT fall through to an INSERT of a bare route_id row,
        // which would violate the NOT NULL stage/version columns and abort the migration.
        $this->repository->insertOrUpdate(['route_id' => null], self::DIMENSION_TABLE, Argument::cetera())
            ->shouldNotBeCalled();

        // The stale route is still removed so the published document can take the slug.
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::CONFLICT_ROUTE_ID])
            ->shouldBeCalled()
            ->willReturn(1);

        $routes = $this->invokeCreateOrUpdateRoutes();

        $this->assertSame(100, $routes['de']['id']);
    }

    public function testReclaimDetachesDimensionContentWhenConflictingRouteIsReferenced(): void
    {
        $this->stubRouteCreation();

        // The conflicting route is owned by an unpublished (draft) page.
        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => 'other-uuid',
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn(['workflowPlace' => 'draft']);

        // A draft dimension content still references the conflicting route.
        $this->repository->exists(self::DIMENSION_TABLE, ['route_id' => self::CONFLICT_ROUTE_ID])
            ->willReturn(true);

        // It must be detached (route_id set to null) before the route is deleted, otherwise the
        // ON DELETE CASCADE foreign key would drop the migrated draft content.
        $this->repository->insertOrUpdate(
            ['route_id' => null],
            self::DIMENSION_TABLE,
            ['route_id' => 'integer'],
            ['route_id' => self::CONFLICT_ROUTE_ID],
        )->shouldBeCalled();

        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::CONFLICT_ROUTE_ID])
            ->shouldBeCalled()
            ->willReturn(1);

        $routes = $this->invokeCreateOrUpdateRoutes();

        $this->assertSame(100, $routes['de']['id']);
    }

    /**
     * Stub the calls shared by the reclaim scenarios: a different resource already owns the incoming
     * slug, and the incoming document's own route is (re)created afterwards.
     */
    private function stubRouteCreation(): void
    {
        // History cleanup for the incoming slug.
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, Argument::that(
            static fn (array $where): bool => ($where['resource_key'] ?? null) === AbstractPersister::ROUTE_RESOURCE_KEY
        ))->willReturn(0);

        // A different resource already owns the (webspace, locale, slug).
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => '/foo',
        ])->willReturn([
            'id' => self::CONFLICT_ROUTE_ID,
            'resource_id' => 'other-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
        ]);

        // The freshly created route for the incoming document (insertOrUpdate returns void).
        $this->repository->insertOrUpdate(Argument::any(), AbstractPersister::ROUTE_TABLE, Argument::cetera());
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'resource_id' => 'incoming-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
        ])->willReturn(['id' => 100]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function invokeCreateOrUpdateRoutes(): array
    {
        $document = [
            'jcr' => ['uuid' => 'incoming-uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => [
                'de' => [
                    'state' => 2,
                    'template' => 'default',
                    AbstractPersister::URL => '/foo',
                ],
            ],
        ];

        $reflection = new \ReflectionMethod(PagePersister::class, 'createOrUpdateRoutes');

        /** @var array<string, array<string, mixed>> $routes */
        $routes = $reflection->invoke($this->persister, $document, false);

        return $routes;
    }
}
