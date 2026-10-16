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
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\UniqueKeyViolationException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository\DryRunWriteLayer;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository\EntityRepository;

#[CoversClass(EntityRepository::class)]
#[CoversClass(DryRunWriteLayer::class)]
final class EntityRepositoryDryRunTest extends TestCase
{
    private const ROUTE_TYPES = ['slug' => 'string'];

    private Connection $connection;

    private EntityRepository $repository;

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
            'CREATE TABLE pa_pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) NOT NULL,
                depth INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->connection->executeStatement('CREATE UNIQUE INDEX pa_pages_uuid ON pa_pages (uuid)');
        $this->connection->executeStatement(
            'CREATE TABLE pa_page_contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) NOT NULL,
                workflow_place VARCHAR(20) DEFAULT NULL,
                template_data TEXT DEFAULT NULL
            )'
        );
        $this->connection->executeStatement('CREATE UNIQUE INDEX pa_page_contents_uuid ON pa_page_contents (uuid)');
        $this->connection->executeStatement(
            'CREATE TABLE pa_page_categories (
                page_dimension_content_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                PRIMARY KEY (page_dimension_content_id, category_id)
            )'
        );

        $this->repository = new EntityRepository($this->connection);
        $this->repository->setDryRun(true);
    }

    public function testFindsRowOfDatabase(): void
    {
        $id = $this->insertRoute('pages', 'uuid-1', '/foo');

        $route = $this->repository->findOneBy('ro_routes', ['webspace' => 'website', 'locale' => 'de', 'slug' => '/foo']);

        $this->assertNotNull($route);
        $this->assertEquals($id, $route['id']);
        $this->assertSame('uuid-1', $route['resource_id']);
    }

    public function testFindsNothingForUnknownRow(): void
    {
        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/missing']));
        $this->assertFalse($this->repository->exists('ro_routes', ['slug' => '/missing']));
        $this->assertSame([], $this->repository->findBy('ro_routes', ['slug' => '/missing']));
    }

    public function testFindsInsertedRowWithoutWritingIt(): void
    {
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES, ['resource_id' => 'uuid-1']);

        $route = $this->repository->findOneBy('ro_routes', ['resource_id' => 'uuid-1']);

        $this->assertNotNull($route);
        $this->assertSame('/foo', $route['slug']);
        $this->assertSame(-1, $route['id']);
        $this->assertTrue($this->repository->exists('ro_routes', ['id' => -1]));
        $this->assertCount(1, $this->repository->findBy('ro_routes', ['locale' => 'de']));
        $this->assertSame(0, $this->countRows('ro_routes'));
    }

    public function testInsertedRowsGetDistinctIds(): void
    {
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES);
        $this->repository->insertOrUpdate($this->routeData('uuid-2', '/bar'), 'ro_routes', self::ROUTE_TYPES);

        $first = $this->repository->findOneBy('ro_routes', ['resource_id' => 'uuid-1']);
        $second = $this->repository->findOneBy('ro_routes', ['resource_id' => 'uuid-2']);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function testUpdatesInsertedRow(): void
    {
        $where = ['resource_id' => 'uuid-1'];
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES, $where);
        $this->repository->insertOrUpdate(['slug' => '/bar'], 'ro_routes', self::ROUTE_TYPES, $where);

        $this->assertCount(1, $this->repository->findBy('ro_routes', $where));
        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));
        $this->assertNotNull($this->repository->findOneBy('ro_routes', ['slug' => '/bar']));
    }

    public function testLookupsFollowChangesOfInsertedRow(): void
    {
        $where = ['resource_id' => 'uuid-1'];
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES, $where);
        $this->assertNotNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));

        $this->repository->insertOrUpdate(['slug' => '/bar'], 'ro_routes', self::ROUTE_TYPES, $where);

        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));
        $this->assertNotNull($this->repository->findOneBy('ro_routes', ['slug' => '/bar']));

        $this->repository->removeBy('ro_routes', $where);

        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/bar']));
    }

    public function testUpdatesRowOfDatabaseWithoutWritingIt(): void
    {
        $id = $this->insertRoute('pages', 'uuid-1', '/foo');

        $this->repository->insertOrUpdate(['slug' => '/bar'], 'ro_routes', self::ROUTE_TYPES, ['id' => $id]);

        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));
        $route = $this->repository->findOneBy('ro_routes', ['slug' => '/bar']);
        $this->assertNotNull($route);
        $this->assertEquals($id, $route['id']);
        $this->assertSame('uuid-1', $route['resource_id']);
        $this->assertSame('/foo', $this->connection->fetchOne('SELECT slug FROM ro_routes WHERE id = ?', [$id]));
    }

    public function testUpdatesEveryMatchingRow(): void
    {
        $this->insertRoute('pages', 'uuid-1', '/foo', 7);
        $this->insertRoute('pages', 'uuid-2', '/bar', 7);
        $this->insertRoute('pages', 'uuid-3', '/baz', 8);

        $this->repository->insertOrUpdate(['parent_id' => null], 'ro_routes', ['parent_id' => 'integer'], ['parent_id' => 7]);

        $this->assertCount(0, $this->repository->findBy('ro_routes', ['parent_id' => 7]));
        $this->assertCount(2, $this->repository->findBy('ro_routes', ['parent_id' => null]));
        $this->assertCount(1, $this->repository->findBy('ro_routes', ['parent_id' => 8]));
    }

    public function testRemovesRowOfDatabaseWithoutDeletingIt(): void
    {
        $this->insertRoute('pages', 'uuid-1', '/foo');
        $this->insertRoute('pages', 'uuid-2', '/bar');

        $removed = $this->repository->removeBy('ro_routes', ['slug' => '/foo']);

        $this->assertSame(1, $removed);
        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));
        $this->assertFalse($this->repository->exists('ro_routes', ['slug' => '/foo']));
        $this->assertNotNull($this->repository->findOneBy('ro_routes', ['slug' => '/bar']));
        $this->assertSame(2, $this->countRows('ro_routes'));
    }

    public function testRemovesInsertedRow(): void
    {
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES);

        $this->assertSame(1, $this->repository->removeBy('ro_routes', ['slug' => '/foo']));
        $this->assertSame(0, $this->repository->removeBy('ro_routes', ['slug' => '/foo']));
        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));
    }

    public function testInsertsRowWhoseKeyIsFreedByRemoval(): void
    {
        $this->insertRoute('pages', 'uuid-1', '/foo');

        $this->repository->removeBy('ro_routes', ['slug' => '/foo']);
        $this->repository->insertOrUpdate($this->routeData('uuid-2', '/foo'), 'ro_routes', self::ROUTE_TYPES, ['resource_id' => 'uuid-2']);

        $route = $this->repository->findOneBy('ro_routes', ['slug' => '/foo']);
        $this->assertNotNull($route);
        $this->assertSame('uuid-2', $route['resource_id']);
    }

    public function testRejectsInsertOfTakenUniqueKey(): void
    {
        $this->insertRoute('pages', 'uuid-1', '/foo');

        $this->expectException(UniqueKeyViolationException::class);
        $this->expectExceptionMessage("Duplicate entry 'website-de-/foo' for key 'ro_routes.ro_routes_unique'");

        $this->repository->insertOrUpdate($this->routeData('uuid-2', '/foo'), 'ro_routes', self::ROUTE_TYPES, ['resource_id' => 'uuid-2']);
    }

    public function testRejectsInsertOfUniqueKeyTakenByInsertedRow(): void
    {
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES, ['resource_id' => 'uuid-1']);

        $this->expectException(UniqueKeyViolationException::class);

        $this->repository->insertOrUpdate($this->routeData('uuid-2', '/foo'), 'ro_routes', self::ROUTE_TYPES, ['resource_id' => 'uuid-2']);
    }

    public function testRejectsUpdateToTakenUniqueKey(): void
    {
        $this->insertRoute('pages', 'uuid-1', '/foo');
        $id = $this->insertRoute('pages', 'uuid-2', '/bar');

        $this->expectException(UniqueKeyViolationException::class);

        $this->repository->insertOrUpdate(['slug' => '/foo'], 'ro_routes', self::ROUTE_TYPES, ['id' => $id]);
    }

    public function testAllowsUpdateKeepingOwnUniqueKey(): void
    {
        $id = $this->insertRoute('pages', 'uuid-1', '/foo');

        $this->repository->insertOrUpdate(['slug' => '/foo', 'parent_id' => 3], 'ro_routes', self::ROUTE_TYPES, ['id' => $id]);

        $route = $this->repository->findOneBy('ro_routes', ['slug' => '/foo']);
        $this->assertNotNull($route);
        $this->assertEquals(3, $route['parent_id']);
    }

    public function testAllowsSameSlugInOtherLocale(): void
    {
        $this->insertRoute('pages', 'uuid-1', '/foo');

        $this->repository->insertOrUpdate(
            ['locale' => 'en'] + $this->routeData('uuid-1', '/foo'),
            'ro_routes',
            self::ROUTE_TYPES,
            ['resource_id' => 'uuid-1', 'locale' => 'en'],
        );

        $this->assertCount(2, $this->repository->findBy('ro_routes', ['slug' => '/foo']));
    }

    public function testNullDoesNotCollideInUniqueKey(): void
    {
        $this->connection->executeStatement('CREATE TABLE nullable_unique (code VARCHAR(10) DEFAULT NULL)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX nullable_unique_code ON nullable_unique (code)');

        $this->repository->insertOrUpdate(['code' => null], 'nullable_unique', []);
        $this->repository->insertOrUpdate(['code' => null], 'nullable_unique', []);

        $this->assertCount(2, $this->repository->findBy('nullable_unique', ['code' => null]));
    }

    public function testFindsRowsByLooselyTypedValues(): void
    {
        $this->repository->insertOrUpdate(['parent_id' => 5] + $this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES);

        $this->assertNotNull($this->repository->findOneBy('ro_routes', ['parent_id' => '5']));
    }

    public function testMatchesColumnsCaseInsensitively(): void
    {
        // PostgreSQL returns the columns of a migrated database in lower case
        $this->connection->executeStatement('CREATE TABLE pa_page_dimension_contents (id INTEGER PRIMARY KEY AUTOINCREMENT, pageuuid VARCHAR(36), workflowplace VARCHAR(20))');
        $this->connection->insert('pa_page_dimension_contents', ['pageuuid' => 'uuid-1', 'workflowplace' => 'draft']);

        $this->repository->insertOrUpdate(['workflowPlace' => 'published'], 'pa_page_dimension_contents', [], ['pageUuid' => 'uuid-1']);

        $row = $this->repository->findOneBy('pa_page_dimension_contents', ['pageUuid' => 'uuid-1']);
        $this->assertNotNull($row);
        $this->assertSame('published', $row['workflowplace']);
        $this->assertArrayNotHasKey('workflowPlace', $row);
    }

    public function testFindsTableWithoutIdColumn(): void
    {
        $data = ['page_dimension_content_id' => 1, 'category_id' => 2];

        $this->repository->insertOrUpdate($data, 'pa_page_categories', [], $data);
        $this->repository->insertOrUpdate($data, 'pa_page_categories', [], $data);

        $this->assertCount(1, $this->repository->findBy('pa_page_categories', ['page_dimension_content_id' => 1]));
        $this->assertSame(1, $this->repository->removeBy('pa_page_categories', ['page_dimension_content_id' => 1]));
        $this->assertSame([], $this->repository->findBy('pa_page_categories', ['page_dimension_content_id' => 1]));
    }

    public function testRootAndChildNodesHaveDepth(): void
    {
        $this->repository->createOrUpdateRootNode(['uuid' => 'root'], 'pa_pages', [], ['uuid' => 'root']);
        $this->repository->addOrUpdateChildNode(['uuid' => 'child'], 'pa_pages', [], 'root', ['uuid' => 'child']);
        $this->repository->addOrUpdateChildNode(['uuid' => 'grandchild'], 'pa_pages', [], 'child', ['uuid' => 'grandchild']);

        $this->assertEquals(0, $this->repository->findOneBy('pa_pages', ['uuid' => 'root'])['depth'] ?? null);
        $this->assertEquals(1, $this->repository->findOneBy('pa_pages', ['uuid' => 'child'])['depth'] ?? null);
        $this->assertEquals(2, $this->repository->findOneBy('pa_pages', ['uuid' => 'grandchild'])['depth'] ?? null);
        $this->assertSame(0, $this->countRows('pa_pages'));
    }

    public function testUpdatingChildNodeKeepsDepth(): void
    {
        $this->repository->createOrUpdateRootNode(['uuid' => 'root'], 'pa_pages', [], ['uuid' => 'root']);
        $this->repository->addOrUpdateChildNode(['uuid' => 'child'], 'pa_pages', [], 'root', ['uuid' => 'child']);
        $this->repository->addOrUpdateChildNode(['uuid' => 'child'], 'pa_pages', [], 'missing', ['uuid' => 'child']);

        $this->assertEquals(1, $this->repository->findOneBy('pa_pages', ['uuid' => 'child'])['depth'] ?? null);
    }

    public function testChildNodeNeedsParent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parent node missing not found');

        $this->repository->addOrUpdateChildNode(['uuid' => 'child'], 'pa_pages', [], 'missing', ['uuid' => 'child']);
    }

    public function testResetDropsWrites(): void
    {
        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES);

        $this->repository->reset();

        $this->assertNull($this->repository->findOneBy('ro_routes', ['slug' => '/foo']));
    }

    public function testDoesNotKeepTextColumnsOfWrittenRows(): void
    {
        $this->repository->insertOrUpdate(
            ['uuid' => 'uuid-1', 'workflow_place' => 'published', 'template_data' => \str_repeat('a', 1000)],
            'pa_page_contents',
            [],
        );

        $row = $this->repository->findOneBy('pa_page_contents', ['uuid' => 'uuid-1']);

        $this->assertNotNull($row);
        $this->assertSame('published', $row['workflow_place']);
        $this->assertArrayNotHasKey('template_data', $row);
    }

    public function testDoesNotKeepTextColumnsOfUpdatedRowsOfTheDatabase(): void
    {
        $this->connection->insert('pa_page_contents', ['uuid' => 'uuid-1', 'template_data' => '{}']);

        $this->repository->insertOrUpdate(['template_data' => '{"a":1}'], 'pa_page_contents', [], ['uuid' => 'uuid-1']);

        $row = $this->repository->findOneBy('pa_page_contents', ['uuid' => 'uuid-1']);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('template_data', $row);
        $this->assertSame('{}', $this->connection->fetchOne('SELECT template_data FROM pa_page_contents'));
    }

    public function testRejectsLookupByColumnThatIsNotKept(): void
    {
        $this->repository->insertOrUpdate(['uuid' => 'uuid-1', 'template_data' => '{}'], 'pa_page_contents', []);

        $this->expectException(\LogicException::class);

        $this->repository->findOneBy('pa_page_contents', ['template_data' => '{}']);
    }

    public function testWritesWithoutDryRun(): void
    {
        $this->repository->setDryRun(false);

        $this->repository->insertOrUpdate($this->routeData('uuid-1', '/foo'), 'ro_routes', self::ROUTE_TYPES, ['resource_id' => 'uuid-1']);

        $this->assertSame(1, $this->countRows('ro_routes'));
        $this->assertSame(1, $this->repository->removeBy('ro_routes', ['slug' => '/foo']));
        $this->assertSame(0, $this->countRows('ro_routes'));
    }

    /**
     * @return array<string, mixed>
     */
    private function routeData(string $resourceId, string $slug): array
    {
        return [
            'resource_key' => 'pages',
            'resource_id' => $resourceId,
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => $slug,
        ];
    }

    private function insertRoute(string $resourceKey, string $resourceId, string $slug, ?int $parentId = null): int
    {
        $this->connection->insert('ro_routes', [
            'resource_key' => $resourceKey,
            'resource_id' => $resourceId,
            'webspace' => 'website',
            'locale' => 'de',
            'slug' => $slug,
            'parent_id' => $parentId,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function countRows(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        \assert(\is_int($count) || \is_string($count));

        return (int) $count;
    }
}
