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
final class PagePersisterShadowRouteTest extends TestCase
{
    use ProphecyTrait;

    private const DIMENSION_TABLE = 'pa_page_dimension_contents';

    /**
     * @var ObjectProphecy<EntityRepositoryInterface>
     */
    private ObjectProphecy $repository;

    private PagePersister $persister;

    /**
     * @var list<array<string, mixed>>
     */
    private array $inserted = [];

    protected function setUp(): void
    {
        $this->repository = $this->prophesize(EntityRepositoryInterface::class);
        $this->persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $this->repository->reveal(),
            new PathCleanup(new AsciiSlugger()),
        );

        $inserted = &$this->inserted;
        $this->repository->getColumnLength(AbstractPersister::ROUTE_TABLE, 'slug')->willReturn(255);
        $this->repository->removeBy(AbstractPersister::ROUTE_TABLE, Argument::any())->willReturn(0);
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, Argument::any())->willReturn(null);
        $this->repository->insertOrUpdate(Argument::any(), AbstractPersister::ROUTE_TABLE, Argument::cetera())
            ->will(function(array $arguments) use (&$inserted): void {
                $inserted[] = $arguments[0];
            });

        foreach (['en', 'de'] as $locale) {
            $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
                'resource_id' => 'incoming-uuid',
                'resource_key' => 'pages',
                'locale' => $locale,
            ])->willReturn(['id' => 'en' === $locale ? 100 : 101]);
        }
    }

    public function testShadowRouteIsStoredUnderItsOwnSlug(): void
    {
        $this->expectOutputString('');

        $routes = $this->createOrUpdateRoutes([
            'en' => $this->localization('Patents', '/company/patents'),
            'de' => $this->shadowLocalization('Patente', '/unternehmen/patents'),
        ]);

        $this->assertSame(['en' => '/company/patents', 'de' => '/unternehmen/patents'], $this->insertedSlugs());
        $this->assertSame(['en', 'de'], \array_keys($routes));
        $this->assertSame(101, $routes['de']['id']);
    }

    public function testShadowWithoutRouteNodeIsStoredUnderTheSourceSlug(): void
    {
        $this->expectOutputString('');

        $this->createOrUpdateRoutes([
            'en' => $this->localization('Patents', '/company/patents'),
            'de' => $this->shadowLocalization('Patente', null),
        ]);

        $this->assertSame(['en' => '/company/patents', 'de' => '/company/patents'], $this->insertedSlugs());
    }

    public function testShadowSlugTakenByPublishedPageGetsAGeneratedSlugFromItsOwnTitle(): void
    {
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => '/unternehmen/patents',
        ])->willReturn([
            'id' => 99,
            'slug' => '/unternehmen/patents',
            'resource_id' => 'other-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
            'parent_id' => null,
        ]);
        $this->repository->findOneBy(self::DIMENSION_TABLE, [
            'pageUuid' => 'other-uuid',
            'locale' => 'de',
            'stage' => 'draft',
            'version' => 0,
        ])->willReturn(['workflowPlace' => 'published', 'title' => 'Other']);

        $this->expectOutputRegex("~^Generated route '/patente' for document 'incoming-uuid' \\(locale 'de'\\) because slug '/unternehmen/patents' is already used by 'pages::other-uuid'~");

        $this->createOrUpdateRoutes([
            'en' => $this->localization('Patents', '/company/patents'),
            'de' => $this->shadowLocalization('Patente', '/unternehmen/patents'),
        ]);

        $this->assertSame(['en' => '/company/patents', 'de' => '/patente'], $this->insertedSlugs());
    }

    public function testShadowWithTakenSlugAndNoUsableTitleHasNoRoute(): void
    {
        $this->repository->findOneBy(AbstractPersister::ROUTE_TABLE, [
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => '/unternehmen/patents',
        ])->willReturn([
            'id' => 99,
            'slug' => '/unternehmen/patents',
            'resource_id' => 'other-uuid',
            'resource_key' => 'pages',
            'locale' => 'de',
            'parent_id' => null,
        ]);
        $this->repository->findOneBy(self::DIMENSION_TABLE, Argument::any())
            ->willReturn(['workflowPlace' => 'published']);

        $this->expectOutputRegex("~^Skipping route for document 'incoming-uuid' \\(locale 'de'\\): slug '/unternehmen/patents' is already used by 'pages::other-uuid'~");

        $routes = $this->createOrUpdateRoutes([
            'en' => $this->localization('Patents', '/company/patents'),
            'de' => $this->shadowLocalization('???', '/unternehmen/patents'),
        ]);

        $this->assertSame(['en'], \array_keys($routes));
        $this->assertSame(['en' => '/company/patents'], $this->insertedSlugs());
    }

    public function testShadowHistoryRoutesAreSkippedEvenWithItsOwnRoute(): void
    {
        $en = $this->localization('Patents', '/company/patents');
        $en[AbstractPersister::HISTORY_URLS] = ['/company/old-patents'];
        $de = $this->shadowLocalization('Patente', '/unternehmen/patents');
        $de[AbstractPersister::HISTORY_URLS] = ['/unternehmen/alte-patents'];

        $this->createOrUpdateRoutes(['en' => $en, 'de' => $de], true);

        $historySlugs = [];
        foreach ($this->inserted as $data) {
            if (AbstractPersister::ROUTE_RESOURCE_KEY === $data['resource_key']) {
                $historySlugs[$data['locale']] = $data['slug'];
            }
        }

        // The page of the source locale keeps its history, so a missing shadow history is not a skipped run.
        $this->assertSame(['en' => '/company/old-patents'], $historySlugs);
    }

    /**
     * @return array<string, mixed>
     */
    private function localization(string $title, ?string $url): array
    {
        $localization = ['state' => 2, 'template' => 'default', 'title' => $title];
        if (null !== $url) {
            $localization[AbstractPersister::URL] = $url;
        }

        return $localization;
    }

    /**
     * @return array<string, mixed>
     */
    private function shadowLocalization(string $title, ?string $url): array
    {
        return ['shadow-on' => true, 'shadow-base' => 'en'] + $this->localization($title, $url);
    }

    /**
     * @param array<string, array<string, mixed>> $localizations
     *
     * @return array<string, array<string, mixed>>
     */
    private function createOrUpdateRoutes(array $localizations, bool $isLive = false): array
    {
        $document = [
            'jcr' => ['uuid' => 'incoming-uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => $localizations,
        ];

        /** @var array<string, array<string, mixed>> $routes */
        $routes = (new \ReflectionMethod(PagePersister::class, 'createOrUpdateRoutes'))
            ->invoke($this->persister, $document, $isLive);

        return $routes;
    }

    /**
     * @return array<string, string>
     */
    private function insertedSlugs(): array
    {
        $slugs = [];
        foreach ($this->inserted as $data) {
            if ('pages' === $data['resource_key']) {
                $slugs[$data['locale']] = $data['slug'];
            }
        }

        return $slugs;
    }
}
