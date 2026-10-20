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
use Sulu\Route\Application\ResourceLocator\PathCleanup\PathCleanup;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[CoversClass(AbstractPersister::class)]
#[CoversClass(PagePersister::class)]
final class AbstractPersisterGeneratedSlugTest extends TestCase
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
            new PathCleanup(new AsciiSlugger()),
        );

        $this->repository->getColumnLength(AbstractPersister::ROUTE_TABLE, 'slug')->willReturn(255);
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, Argument::that(
            static fn (array $where): bool => ($where['resource_key'] ?? null) === AbstractPersister::ROUTE_RESOURCE_KEY
        ))->willReturn(0);
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'resource_id' => 'incoming-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
        ])->willReturn(['id' => 100]);
    }

    public function testReclaimedDraftRouteMovesToGeneratedSlug(): void
    {
        $this->stubRouteOwner('/foo', 'other-uuid');
        $this->stubRouteOwner('/bar', null);

        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => 'other-uuid',
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn(['workflowPlace' => 'draft', 'title' => 'Bar']);

        // The losing draft keeps its route under a new slug instead of being detached from it.
        $this->repository->insertOrUpdate(
            ['slug' => '/bar'],
            AbstractPersister::ROUTE_TABLE,
            ['slug' => 'string'],
            ['id' => self::CONFLICT_ROUTE_ID],
        )->shouldBeCalled();
        $this->repository->insertOrUpdate(['route_id' => null], self::DIMENSION_TABLE, Argument::cetera())
            ->shouldNotBeCalled();
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::CONFLICT_ROUTE_ID])
            ->shouldNotBeCalled();

        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => '/foo' === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->expectOutputRegex("~Generated route '/bar' for document 'other-uuid' \(locale 'de'\) because slug '/foo' is taken by published 'pages::incoming-uuid'~");

        $this->invokeCreateOrUpdateRoutes(2, 'Foo');
    }

    public function testReclaimedDraftWithSameTitleDoesNotKeepReclaimedSlug(): void
    {
        $this->stubRouteOwner('/foo', 'other-uuid');
        $this->stubRouteOwner('/foo-1', null);

        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => 'other-uuid',
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn(['workflowPlace' => 'draft', 'title' => 'Foo']);

        $this->repository->insertOrUpdate(
            ['slug' => '/foo-1'],
            AbstractPersister::ROUTE_TABLE,
            ['slug' => 'string'],
            ['id' => self::CONFLICT_ROUTE_ID],
        )->shouldBeCalled();
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => '/foo' === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->expectOutputRegex("~Generated route '/foo-1' for document 'other-uuid'~");

        $this->invokeCreateOrUpdateRoutes(2, 'Foo');
    }

    public function testGeneratedSlugStartsWithParentSlug(): void
    {
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'resource_key' => 'pages',
            'resource_id' => 'parent-uuid',
            'locale' => 'de',
        ])->willReturn(['id' => 5]);
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, ['id' => 5])->willReturn(['id' => 5, 'slug' => '/parent']);
        $this->stubRouteOwner('/parent/foo', null);

        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => '/parent/foo' === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->expectOutputRegex("~^Generated route '/parent/foo' for document 'incoming-uuid'~");

        $this->invokeCreateOrUpdateRoutes(1, 'Foo', null, 'parent-uuid');
    }

    public function testTooLongGeneratedSlugSkipsRoute(): void
    {
        $this->stubRouteOwner('/foo', 'other-uuid');
        $this->stubRouteOwner('/' . \str_repeat('a', 300), null);

        $this->repository->insertOrUpdate(Argument::any(), AbstractPersister::ROUTE_TABLE, Argument::cetera())
            ->shouldNotBeCalled();

        $this->expectOutputRegex("~^Skipping route for document 'incoming-uuid' \\(locale 'de'\\): slug '/foo' is already used by 'pages::other-uuid'~");

        $this->invokeCreateOrUpdateRoutes(1, \str_repeat('a', 300));
    }

    public function testLocaleWithoutUrlOrUsableTitleIsReported(): void
    {
        $this->repository->insertOrUpdate(Argument::any(), AbstractPersister::ROUTE_TABLE, Argument::cetera())
            ->shouldNotBeCalled();

        $this->expectOutputString("Skipping route for document 'incoming-uuid' (locale 'de'): it has no stored url and no slug could be generated.\n");

        $this->invokeCreateOrUpdateRoutes(1, '???', null);
    }

    public function testRemovedRouteOfReclaimedDraftIsReported(): void
    {
        $this->stubRouteOwner('/foo', 'other-uuid');

        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => 'other-uuid',
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn(['workflowPlace' => 'draft', 'title' => '???']);
        $this->repository->exists(self::DIMENSION_TABLE, ['route_id' => self::CONFLICT_ROUTE_ID])->willReturn(true);
        $this->repository->insertOrUpdate(['route_id' => null], self::DIMENSION_TABLE, Argument::cetera())
            ->shouldBeCalled();
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::CONFLICT_ROUTE_ID])
            ->willReturn(1)
            ->shouldBeCalled();
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => '/foo' === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->expectOutputString("Removed route of document 'other-uuid' (locale 'de'): slug '/foo' is taken by published 'pages::incoming-uuid' and no slug could be generated.\n");

        $this->invokeCreateOrUpdateRoutes(2, 'Foo');
    }

    public function testDryRunStubRowIsNoConflict(): void
    {
        // In a dry run every lookup returns the same stub row, which has no slug.
        $repository = $this->prophesize(EntityRepositoryInterface::class);
        $repository->getColumnLength(Argument::cetera())->willReturn(255);
        $repository->removeBy(Argument::cetera())->willReturn(0);
        $repository->findOneBy(Argument::cetera())->willReturn(['id' => 0, 'depth' => 0]);
        $repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => '/foo' === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $repository->reveal(),
            new PathCleanup(new AsciiSlugger()),
        );

        $this->expectOutputString('');

        $this->invokeCreateOrUpdateRoutes(1, 'Foo');
    }

    private function stubRouteOwner(string $slug, ?string $resourceId): void
    {
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => $slug,
        ])->willReturn(null === $resourceId ? null : [
            'id' => self::CONFLICT_ROUTE_ID,
            'slug' => $slug,
            'resource_id' => $resourceId,
            'resource_key' => 'pages',
            'locale' => 'de',
            'parent_id' => null,
        ]);
    }

    private function invokeCreateOrUpdateRoutes(int $state, string $title, ?string $url = '/foo', ?string $parentId = null): void
    {
        $localization = [
            'state' => $state,
            'template' => 'default',
            'title' => $title,
        ];
        if (null !== $url) {
            $localization[AbstractPersister::URL] = $url;
        }

        $document = [
            'jcr' => ['uuid' => 'incoming-uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => $parentId],
            'localizations' => ['de' => $localization],
        ];

        $reflection = new \ReflectionMethod(PagePersister::class, 'createOrUpdateRoutes');
        $reflection->invoke($this->persister, $document, false);
    }
}
