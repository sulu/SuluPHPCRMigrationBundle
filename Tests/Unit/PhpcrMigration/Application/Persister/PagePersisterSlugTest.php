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

    public function testShadowKeepsItsOwnRouteNode(): void
    {
        $document = $this->shadowDocument([
            'en' => ['_url' => '/company/patents'],
            'de' => ['shadow-on' => true, 'shadow-base' => 'en', '_url' => '/unternehmen/patents'],
        ]);

        $this->assertSame('/unternehmen/patents', $this->getSlug($document, 'de'));
        $this->assertSame('/company/patents', $this->getSlug($document, 'en'));
    }

    public function testShadowWithoutRouteNodeUsesTheSourceSlug(): void
    {
        $document = $this->shadowDocument([
            'en' => ['_url' => '/company/patents'],
            'de' => ['shadow-on' => true, 'shadow-base' => 'en', 'url' => '/unternehmen/patents'],
        ]);

        $this->assertSame('/company/patents', $this->getSlug($document, 'de'));
    }

    /**
     * @param array<string, array<string, mixed>> $localizations
     */
    #[DataProvider('provideSlugResolution')]
    public function testSlugResolution(array $localizations, string $locale, ?string $expected): void
    {
        $this->assertSame($expected, $this->getSlug($this->shadowDocument($localizations), $locale));
    }

    /**
     * @return \Generator<string, array{array<string, array<string, mixed>>, string, string|null}>
     */
    public static function provideSlugResolution(): \Generator
    {
        $shadowOfEn = ['shadow-on' => true, 'shadow-base' => 'en'];

        yield 'page with a route node' => [['en' => ['_url' => '/a', 'url' => '/b']], 'en', '/a'];
        yield 'page without a route node uses its resource locator' => [['en' => ['url' => '/b']], 'en', '/b'];
        yield 'page without any url' => [['en' => []], 'en', null];
        yield 'null route node counts as no route' => [
            ['en' => ['_url' => '/en'], 'de' => $shadowOfEn + ['_url' => null]],
            'de',
            '/en',
        ];
        yield 'shadow without a route node uses the resource locator of its source' => [
            ['en' => ['url' => '/en-draft'], 'de' => $shadowOfEn],
            'de',
            '/en-draft',
        ];
        yield 'shadow without a route node and a source without url has no slug' => [
            ['en' => [], 'de' => $shadowOfEn + ['url' => '/de-draft']],
            'de',
            null,
        ];
        yield 'shadow switched off keeps its route node' => [
            ['en' => ['_url' => '/en'], 'de' => ['shadow-on' => false, 'shadow-base' => 'en', '_url' => '/de']],
            'de',
            '/de',
        ];
        yield 'shadow without a base keeps its route node' => [
            ['en' => ['_url' => '/en'], 'de' => ['shadow-on' => true, '_url' => '/de']],
            'de',
            '/de',
        ];
        yield 'shadow of an unknown locale keeps its route node' => [
            ['de' => ['shadow-on' => true, 'shadow-base' => 'fr', '_url' => '/de', 'url' => '/de-draft']],
            'de',
            '/de',
        ];
        yield 'shadow of an unknown locale without route node uses its resource locator' => [
            ['de' => ['shadow-on' => true, 'shadow-base' => 'fr', 'url' => '/de-draft']],
            'de',
            '/de-draft',
        ];
        yield 'shadow of an unknown locale without any url' => [
            ['de' => ['shadow-on' => true, 'shadow-base' => 'fr']],
            'de',
            null,
        ];
        yield 'shadow of a shadow without route nodes ends at the final source' => [
            [
                'en' => ['_url' => '/en'],
                'de' => $shadowOfEn,
                'fr' => ['shadow-on' => true, 'shadow-base' => 'de'],
            ],
            'fr',
            '/en',
        ];
        yield 'shadow of a shadow stops at the route node of the middle one' => [
            [
                'en' => ['_url' => '/en'],
                'de' => $shadowOfEn + ['_url' => '/de'],
                'fr' => ['shadow-on' => true, 'shadow-base' => 'de'],
            ],
            'fr',
            '/de',
        ];
        yield 'cycle ends at the first route node' => [
            [
                'de' => ['shadow-on' => true, 'shadow-base' => 'en'],
                'en' => ['shadow-on' => true, 'shadow-base' => 'de', '_url' => '/en'],
            ],
            'de',
            '/en',
        ];
        yield 'cycle with route nodes keeps each own' => [
            [
                'de' => ['shadow-on' => true, 'shadow-base' => 'en', '_url' => '/de'],
                'en' => ['shadow-on' => true, 'shadow-base' => 'de', '_url' => '/en'],
            ],
            'de',
            '/de',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $localizations
     *
     * @return array<string, mixed>
     */
    private function shadowDocument(array $localizations): array
    {
        return [
            'jcr' => ['uuid' => 'uuid', 'mixinTypes' => ['sulu:page']],
            'sulu' => ['webspaceKey' => 'website', 'parentId' => null],
            'localizations' => $localizations,
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function getSlug(array $document, string $locale): ?string
    {
        $persister = new PagePersister(
            PropertyAccess::createPropertyAccessor(),
            $this->prophesize(EntityRepositoryInterface::class)->reveal(),
        );

        $slug = (new \ReflectionMethod(PagePersister::class, 'getSlug'))->invoke($persister, $document, $locale);
        $this->assertTrue(null === $slug || \is_string($slug));

        return $slug;
    }
}
