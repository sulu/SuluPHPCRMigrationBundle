<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser;

use PHPCR\NodeInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\LegacyRouteTableNotFoundException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor\LocaleExtractor;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

class ArticleNodeParser implements NodeParserInterface
{
    public const LEGACY_ROUTE_TABLE = 'ro_routes_old';

    public function __construct(
        private readonly EntityRepositoryInterface $repository,
        private readonly LocaleExtractor $localeExtractor,
    ) {
    }

    public function parse(NodeInterface $node, string $documentType): array
    {
        if (!$this->supports($node, $documentType)) {
            return [];
        }

        $localizations = [];
        $localizations = $this->parseLocalizedRoutes($node, $localizations);

        return [
            'localizations' => $localizations,
        ];
    }

    private function supports(NodeInterface $node, string $documentType): bool
    {
        if ('article' !== $documentType) {
            return false;
        }

        foreach ($node->getMixinNodeTypes() as $mixinNodeType) {
            if ('sulu:article' == $mixinNodeType->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $localizations
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseLocalizedRoutes(NodeInterface $node, array $localizations): array
    {
        if (!$this->repository->tableExists(self::LEGACY_ROUTE_TABLE)) {
            throw new LegacyRouteTableNotFoundException(self::LEGACY_ROUTE_TABLE);
        }

        $routes = $this->repository->findBy(
            self::LEGACY_ROUTE_TABLE,
            [
                'entity_id' => $node->getIdentifier(),
            ]
        );

        $discoveredLocales = $this->localeExtractor->extract($node);
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                continue;
            }

            $locale = $route['locale'] ?? null;
            $url = $route['path'] ?? null;

            if ($locale && $url && isset($discoveredLocales[$locale])) {
                match ((bool) ($route['history'] ?? false)) {
                    true => $localizations[$locale][AbstractPersister::HISTORY_URLS][] = $url,
                    false => $localizations[$locale][AbstractPersister::URL] = $url,
                };
            }
        }

        return $localizations;
    }
}
