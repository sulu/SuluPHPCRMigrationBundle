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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\AbstractPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

class ArticleNodeParser implements NodeParserInterface
{
    public const LEGACY_ROUTE_TABLE = 'ro_routes';

    public function __construct(private readonly EntityRepositoryInterface $repository)
    {
    }

    public function parse(NodeInterface $node): array
    {
        if (!$this->supports($node)) {
            return [];
        }

        $localizations = [];
        $localizations = $this->parseLocalizedRoutes($node, $localizations);

        return [
            'localizations' => $localizations,
        ];
    }

    private function supports(NodeInterface $node): bool
    {
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
        $routes = $this->repository->findBy(
            self::LEGACY_ROUTE_TABLE,
            [
                'entity_id' => $node->getIdentifier(),
            ]
        );

        foreach ($routes as $route) {
            if (!\is_array($route)) {
                continue;
            }

            $locale = $route['locale'] ?? null;
            $url = $route['path'] ?? null;

            if ($locale && $url) {
                match ((bool) ($route['history'] ?? false)) {
                    true => $localizations[$locale][AbstractPersister::HISTORY_URLS][] = $url,
                    false => $localizations[$locale][AbstractPersister::URL] = $url,
                };
            }
        }

        return $localizations;
    }
}
