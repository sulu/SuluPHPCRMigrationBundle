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
use Sulu\Article\Application\Webspace\WebspaceSettingsConfigurationResolver;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\ArticlePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(ArticlePersister::class)]
final class ArticlePersisterMainWebspaceTest extends TestCase
{
    use ProphecyTrait;

    public function testSingleWebspaceIsUsedWithoutConfiguredDefault(): void
    {
        $this->assertSame('website', $this->mapMainWebspace(['website']));
    }

    public function testMultipleWebspacesWithoutConfiguredDefaultThrow(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->mapMainWebspace(['website', 'website_2']);
    }

    public function testMultipleWebspacesWithoutDefaultForLocaleThrow(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->mapMainWebspace(['website', 'website_2'], ['en' => 'website']);
    }

    public function testMultipleWebspacesUseConfiguredDefault(): void
    {
        $this->assertSame('website_2', $this->mapMainWebspace(['website', 'website_2'], ['default' => 'website_2']));
    }

    public function testConfiguredDefaultWinsOverSingleWebspace(): void
    {
        $this->assertSame('website_2', $this->mapMainWebspace(['website'], ['default' => 'website_2']));
    }

    public function testExplicitPhpcrMainWebspaceIsKept(): void
    {
        $this->assertSame('website_2', $this->mapMainWebspace(['website'], [], 'website_2'));
    }

    /**
     * @param string[] $webspaceKeys
     * @param array<string, string> $defaultMainWebspaceMap
     */
    private function mapMainWebspace(array $webspaceKeys, array $defaultMainWebspaceMap = [], ?string $phpcrMainWebspace = null): ?string
    {
        $webspaces = [];
        foreach ($webspaceKeys as $webspaceKey) {
            $webspace = new Webspace();
            $webspace->setKey($webspaceKey);
            $webspaces[$webspaceKey] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $persister = new ArticlePersister(
            PropertyAccess::createPropertyAccessor(),
            $this->prophesize(EntityRepositoryInterface::class)->reveal(),
            new WebspaceSettingsConfigurationResolver($defaultMainWebspaceMap, [], $webspaceManager->reveal()),
        );

        $document = [
            'jcr' => ['uuid' => 'uuid', 'mixinTypes' => ['sulu:article']],
            'localizations' => ['de' => null === $phpcrMainWebspace ? [] : ['mainWebspace' => $phpcrMainWebspace]],
        ];
        $data = null === $phpcrMainWebspace ? [] : ['mainWebspace' => $phpcrMainWebspace];

        /** @var array<string, mixed> $result */
        $result = (new \ReflectionMethod(ArticlePersister::class, 'mapDimensionContentData'))
            ->invoke($persister, $document, 'de', $data, false);

        $mainWebspace = $result['mainWebspace'] ?? null;
        $this->assertTrue(null === $mainWebspace || \is_string($mainWebspace));

        return $mainWebspace;
    }
}
