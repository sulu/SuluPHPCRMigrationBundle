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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\UniqueKeyViolationException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository\EntityRepository;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * The dry run has to find the same route collisions as the real run, so it works on the rows of the database.
 */
#[CoversClass(AbstractPersister::class)]
final class PagePersisterDryRunTest extends TestCase
{
    private Connection $connection;

    private PagePersister $persister;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ro_routes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_key VARCHAR(100) NOT NULL,
                resource_id VARCHAR(100) NOT NULL,
                webspace VARCHAR(100) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                parent_id INTEGER DEFAULT NULL
            )'
        );
        $this->connection->executeStatement('CREATE UNIQUE INDEX ro_routes_unique ON ro_routes (webspace, locale, slug)');
        $this->connection->executeStatement(
            'CREATE TABLE pa_page_dimension_contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pageUuid VARCHAR(36) NOT NULL,
                locale VARCHAR(10) DEFAULT NULL,
                stage VARCHAR(10) NOT NULL,
                version INTEGER NOT NULL,
                workflowPlace VARCHAR(20) DEFAULT NULL,
                route_id INTEGER DEFAULT NULL
            )'
        );

        $repository = new EntityRepository($this->connection);
        $repository->setDryRun(true);

        $this->persister = new PagePersister(PropertyAccess::createPropertyAccessor(), $repository);
    }

    public function testRouteOfOtherPageIsReportedWithItsOwner(): void
    {
        $this->expectOutputString("Skipping route for document 'uuid-2' (locale 'de'): slug '/foo' is already used by 'pages::uuid-1'.\n");

        $this->createRoutes('uuid-1', 1, '/foo');
        $this->createRoutes('uuid-2', 1, '/foo');
    }

    public function testFreeRouteIsNotReported(): void
    {
        $this->expectOutputString('');

        $this->createRoutes('uuid-1', 1, '/foo');
        $this->createRoutes('uuid-2', 1, '/bar');
        $this->createRoutes('uuid-1', 1, '/foo');
    }

    public function testRouteOfRealRunIsReported(): void
    {
        $this->connection->insert('ro_routes', [
            'resource_key' => 'pages',
            'resource_id' => 'uuid-1',
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => '/foo',
        ]);

        $this->expectOutputString("Skipping route for document 'uuid-2' (locale 'de'): slug '/foo' is already used by 'pages::uuid-1'.\n");

        $this->createRoutes('uuid-2', 1, '/foo');
    }

    public function testPublishedPageTakesRouteOfDraft(): void
    {
        $this->expectOutputString('');

        $this->createRoutes('uuid-1', 1, '/foo');
        $routes = $this->createRoutes('uuid-2', 2, '/foo');

        $this->assertSame('uuid-2', $routes['de']['resource_id']);
    }

    public function testHistoryRouteOfOtherPageIsAUniqueKeyViolation(): void
    {
        $this->createRoutes('uuid-1', 1, '/foo');

        $this->expectException(UniqueKeyViolationException::class);
        $this->expectExceptionMessage("Duplicate entry 'website-de-/foo' for key 'ro_routes.ro_routes_unique'");

        $this->createRoutes('uuid-2', 2, '/bar', true, ['/foo']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->createRoutes('uuid-1', 2, '/foo', true, ['/old']);

        $this->assertEquals(0, $this->connection->fetchOne('SELECT COUNT(*) FROM ro_routes'));
    }

    /**
     * @param list<string> $historyUrls
     *
     * @return array<string, array<string, mixed>>
     */
    private function createRoutes(string $uuid, int $state, string $url, bool $isLive = false, array $historyUrls = []): array
    {
        $document = [
            'jcr' => ['uuid' => $uuid, 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => [
                'de' => [
                    'state' => $state,
                    'template' => 'default',
                    AbstractPersister::URL => $url,
                    AbstractPersister::HISTORY_URLS => $historyUrls,
                ],
            ],
        ];

        $reflection = new \ReflectionMethod(PagePersister::class, 'createOrUpdateRoutes');

        /** @var array<string, array<string, mixed>> $routes */
        $routes = $reflection->invoke($this->persister, $document, $isLive);

        return $routes;
    }
}
