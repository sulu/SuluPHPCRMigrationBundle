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
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(PagePersister::class)]
final class PagePersisterSlugTest extends TestCase
{
    use ProphecyTrait;

    public function testGetSlugTerminatesOnCyclicShadowReference(): void
    {
        $repository = $this->prophesize(EntityRepositoryInterface::class);
        $persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $repository->reveal(),
        );

        // Malformed data: each locale is a shadow of the other. Without a visited-set guard this
        // would recurse forever and exhaust the stack.
        $document = [
            'jcr' => ['uuid' => 'uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => [
                'de' => ['shadow-on' => true, 'shadow-base' => 'en', 'url' => '/de'],
                'en' => ['shadow-on' => true, 'shadow-base' => 'de', 'url' => '/en'],
            ],
        ];

        $slug = (new \ReflectionMethod(PagePersister::class, 'getSlug'))->invoke($persister, $document, 'de');

        // The cycle is broken and the starting locale falls back to its own resource locator.
        $this->assertSame('/de', $slug);
    }
}
