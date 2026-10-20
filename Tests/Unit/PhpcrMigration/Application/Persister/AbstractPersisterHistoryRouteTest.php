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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\HistoryRouteSlugTakenException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Sulu\Route\Application\ResourceLocator\PathCleanup\PathCleanup;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[CoversClass(AbstractPersister::class)]
#[CoversClass(HistoryRouteSlugTakenException::class)]
final class AbstractPersisterHistoryRouteTest extends TestCase
{
    use ProphecyTrait;

    private const DIMENSION_TABLE = 'pa_page_dimension_contents';
    private const OWNER_ROUTE_ID = 99;
    private const HISTORY_SLUG = '/news/success';

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
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => '/news/success-page',
        ])->willReturn(null);
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'resource_id' => 'incoming-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
        ])->willReturn(['id' => 100]);
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => '/news/success-page' === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        );
    }

    public function testHistorySlugOfUnpublishedPageWithoutUsableTitleDetachesItsRoute(): void
    {
        $this->stubHistorySlugOwner('pages', 'draft-uuid');
        $this->stubDraftOwner('draft-uuid', ['workflowPlace' => 'draft', 'title' => '???']);
        $this->repository->exists(self::DIMENSION_TABLE, ['route_id' => self::OWNER_ROUTE_ID])->willReturn(true);

        $this->repository->insertOrUpdate(
            ['route_id' => null],
            self::DIMENSION_TABLE,
            ['route_id' => 'integer'],
            ['route_id' => self::OWNER_ROUTE_ID],
        )->shouldBeCalled();
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::OWNER_ROUTE_ID])
            ->willReturn(1)
            ->shouldBeCalled();
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => self::HISTORY_SLUG === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->expectOutputString("Removed route of document 'draft-uuid' (locale 'de'): slug '/news/success' is taken by the history route of published 'pages::incoming-uuid' and no slug could be generated.\n");

        $this->invokeCreateOrUpdateRoutes();
    }

    public function testHistorySlugOfPublishedPageAborts(): void
    {
        $this->stubHistorySlugOwner('pages', 'published-uuid');
        $this->stubDraftOwner('published-uuid', ['workflowPlace' => 'published', 'title' => 'Success']);

        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::OWNER_ROUTE_ID])
            ->shouldNotBeCalled();
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => self::HISTORY_SLUG === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldNotBeCalled();

        $this->expectException(HistoryRouteSlugTakenException::class);
        $this->expectExceptionMessage('History route "/news/success" of document "incoming-uuid" (locale: de) cannot be migrated, its slug is used by the published "pages::published-uuid".');

        $this->invokeCreateOrUpdateRoutes();
    }

    public function testHistorySlugOfOtherResourceTypeAborts(): void
    {
        // Only owners of the same type can be resolved, so any other one counts as published.
        $this->stubHistorySlugOwner('articles', 'article-uuid');

        $this->expectException(HistoryRouteSlugTakenException::class);
        $this->expectExceptionMessage('the published "articles::article-uuid"');

        $this->invokeCreateOrUpdateRoutes();
    }

    public function testHistoryUrlEqualToOwnSlugIsSkipped(): void
    {
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => '/news/success-page',
        ])->willReturn([
            'id' => 100,
            'slug' => '/news/success-page',
            'resource_id' => 'incoming-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
            'parent_id' => null,
        ]);
        $this->repository->exists(Argument::cetera())->shouldNotBeCalled();
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => AbstractPersister::ROUTE_RESOURCE_KEY === ($data['resource_key'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldNotBeCalled();

        $this->invokeCreateOrUpdateRoutes(['/news/success-page']);
    }

    public function testExistingHistoryRouteOfSamePageIsUpdated(): void
    {
        // A second run finds the history route it wrote the first time.
        $this->stubHistorySlugOwner(AbstractPersister::ROUTE_RESOURCE_KEY, 'pages::incoming-uuid');

        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, ['id' => self::OWNER_ROUTE_ID])
            ->shouldNotBeCalled();
        $this->repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => self::HISTORY_SLUG === ($data['slug'] ?? null)),
            AbstractPersister::ROUTE_TABLE,
            Argument::cetera(),
        )->shouldBeCalled();

        $this->expectOutputString('');

        $this->invokeCreateOrUpdateRoutes();
    }

    private function stubHistorySlugOwner(string $resourceKey, string $resourceId): void
    {
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => self::HISTORY_SLUG,
        ])->willReturn([
            'id' => self::OWNER_ROUTE_ID,
            'slug' => self::HISTORY_SLUG,
            'resource_id' => $resourceId,
            'resource_key' => $resourceKey,
            'locale' => 'de',
            'parent_id' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $dimensionContent
     */
    private function stubDraftOwner(string $resourceId, array $dimensionContent): void
    {
        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => $resourceId,
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn($dimensionContent);
    }

    /**
     * @param list<string> $historyUrls
     */
    private function invokeCreateOrUpdateRoutes(array $historyUrls = [self::HISTORY_SLUG]): void
    {
        $document = [
            'jcr' => ['uuid' => 'incoming-uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => [
                'de' => [
                    'state' => 2,
                    'template' => 'default',
                    'title' => 'Success Page',
                    AbstractPersister::URL => '/news/success-page',
                    AbstractPersister::HISTORY_URLS => $historyUrls,
                ],
            ],
        ];

        $reflection = new \ReflectionMethod(PagePersister::class, 'createOrUpdateRoutes');
        $reflection->invoke($this->persister, $document, true);
    }
}
