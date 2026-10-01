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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\SlugTooLongException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(PagePersister::class)]
final class PagePersisterSlugLengthTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<EntityRepositoryInterface>
     */
    private ObjectProphecy $repository;

    private PagePersister $persister;

    protected function setUp(): void
    {
        $this->repository = $this->prophesize(EntityRepositoryInterface::class);
        $this->repository->removeBy(Argument::cetera())->willReturn(0);
        $this->repository->findOneBy(Argument::cetera())->willReturn(null);
        $this->repository->insertOrUpdate(Argument::cetera());

        $this->persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $this->repository->reveal(),
        );
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function provideSlugs(): iterable
    {
        yield 'legacy column, at the limit' => [144, '/' . \str_repeat('a', 143), false];
        yield 'legacy column, one over' => [144, '/' . \str_repeat('a', 144), true];
        yield 'widened column, at the limit' => [255, '/' . \str_repeat('a', 254), false];
        yield 'widened column, one over' => [255, '/' . \str_repeat('a', 255), true];
        yield 'multibyte counts characters, at the limit' => [255, '/' . \str_repeat('語', 254), false];
        yield 'multibyte counts characters, one over' => [255, '/' . \str_repeat('語', 255), true];
    }

    #[DataProvider('provideSlugs')]
    public function testMainRouteSlugIsCheckedAgainstTheColumnLength(int $columnLength, string $slug, bool $tooLong): void
    {
        $this->repository->getColumnLength(AbstractPersister::ROUTE_TABLE, 'slug')->willReturn($columnLength);

        $this->expectTooLongOrNothing($tooLong, $columnLength);

        $this->invokeCreateOrUpdateRoutes($slug, []);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('provideSlugs')]
    public function testHistoryUrlIsCheckedAgainstTheColumnLength(int $columnLength, string $slug, bool $tooLong): void
    {
        $this->repository->getColumnLength(AbstractPersister::ROUTE_TABLE, 'slug')->willReturn($columnLength);

        $this->expectTooLongOrNothing($tooLong, $columnLength);

        $this->invokeCreateOrUpdateRoutes('/current', [$slug]);

        $this->addToAssertionCount(1);
    }

    private function expectTooLongOrNothing(bool $tooLong, int $columnLength): void
    {
        if (!$tooLong) {
            return;
        }

        $this->expectException(SlugTooLongException::class);
        $this->expectExceptionMessage('exceeds the maximum length of ' . $columnLength . ' characters');
    }

    /**
     * @param string[] $historyUrls
     */
    private function invokeCreateOrUpdateRoutes(string $slug, array $historyUrls): void
    {
        $document = [
            'jcr' => ['uuid' => 'uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => [
                'de' => [
                    'state' => 2,
                    'template' => 'default',
                    AbstractPersister::URL => $slug,
                    AbstractPersister::HISTORY_URLS => $historyUrls,
                ],
            ],
        ];

        (new \ReflectionMethod(PagePersister::class, 'createOrUpdateRoutes'))->invoke($this->persister, $document, true);
    }
}
