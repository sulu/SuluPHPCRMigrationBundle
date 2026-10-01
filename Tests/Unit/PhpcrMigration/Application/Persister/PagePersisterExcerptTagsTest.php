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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(PagePersister::class)]
final class PagePersisterExcerptTagsTest extends TestCase
{
    use ProphecyTrait;

    public function testExcerptTagsThatNoLongerExistAreNotInserted(): void
    {
        $repository = $this->prophesize(EntityRepositoryInterface::class);
        $repository->removeBy(Argument::cetera())->willReturn(0);
        $repository->exists('ta_tags', ['id' => 1])->willReturn(true);
        $repository->exists('ta_tags', ['id' => 2])->willReturn(false);

        $persister = new PagePersister(PropertyAccess::createPropertyAccessor(), $repository->reveal());

        (new \ReflectionMethod(PagePersister::class, 'insertOrUpdateExcerptTags'))->invoke(
            $persister,
            ['localizations' => ['en' => ['excerpt' => ['tags' => [1, 2]]]]],
            'en',
            ['id' => 10],
        );

        $repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => 1 === $data['tag_id']),
            Argument::cetera(),
        )->shouldHaveBeenCalledOnce();
        $repository->insertOrUpdate(
            Argument::that(static fn (array $data): bool => 2 === $data['tag_id']),
            Argument::cetera(),
        )->shouldNotHaveBeenCalled();
    }
}
