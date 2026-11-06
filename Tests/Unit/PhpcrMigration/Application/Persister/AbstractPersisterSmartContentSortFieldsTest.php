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
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(AbstractPersister::class)]
final class AbstractPersisterSmartContentSortFieldsTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @param mixed[] $data
     * @param mixed[] $expected
     */
    #[DataProvider('provideData')]
    public function testMapsSortFields(array $data, array $expected): void
    {
        $persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $this->prophesize(EntityRepositoryInterface::class)->reveal(),
        );

        $method = new \ReflectionMethod(AbstractPersister::class, 'mapSmartContentSortFields');

        self::assertSame($expected, $method->invoke($persister, $data));
    }

    /**
     * @return iterable<string, array{mixed[], mixed[]}>
     */
    public static function provideData(): iterable
    {
        yield 'title' => [
            ['articles' => ['sortBy' => 'title.raw', 'sortMethod' => 'asc']],
            ['articles' => ['sortBy' => 'title', 'sortMethod' => 'asc']],
        ];

        yield 'last modified or authored' => [
            ['articles' => ['sortBy' => 'last_modified_or_authored']],
            ['articles' => ['sortBy' => 'authored']],
        ];

        yield 'inside blocks' => [
            ['blocks' => [['type' => 'teasers', 'articles' => ['sortBy' => 'title.raw']]]],
            ['blocks' => [['type' => 'teasers', 'articles' => ['sortBy' => 'title']]]],
        ];

        yield 'supported field stays' => [
            ['articles' => ['sortBy' => 'authored']],
            ['articles' => ['sortBy' => 'authored']],
        ];

        yield 'same value outside sortBy stays' => [
            ['title' => 'title.raw'],
            ['title' => 'title.raw'],
        ];
    }
}
