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

class PageNodeParser implements NodeParserInterface
{
    public function parse(NodeInterface $node): array
    {
        if (!$this->supports($node)) {
            return [];
        }

        $webspaceKey = \explode('/', $node->getPath())[2];
        // we only have a parent id if we are not on the root level
        $parentId = \substr_count($node->getPath(), '/') > 3 ? $node->getParent()->getIdentifier() : null;

        $localizations = [];
        $localizations = $this->parseLocalizedRoutes($node, $localizations);

        return [
            'localizations' => $localizations,
            'sulu' => [
                'webspaceKey' => $webspaceKey,
                'parentId' => $parentId,
            ],
        ];
    }

    private function supports(NodeInterface $node): bool
    {
        foreach ($node->getMixinNodeTypes() as $mixinNodeType) {
            if (\in_array($mixinNodeType->getName(), ['sulu:page', 'sulu:home'])) {
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
        foreach ($node->getReferences('sulu:content') as $reference) {
            $route = $reference->getParent();
            $routePath = $route->getPath();
            $locale = \explode('/', $routePath)[4];
            $slug = '/' . (\explode('/', $routePath, 6)[5] ?? '');

            $historyReferences = $route->getReferences();
            $historyUrls = [];
            foreach ($historyReferences as $historyReference) {
                $historyRoute = $historyReference->getParent();
                $historyUrl = $historyRoute->getPath();
                $historyUrls[] = '/' . (\explode('/', $historyUrl, 6)[5] ?? '');
            }

            $localizations[$locale][AbstractPersister::URL] = $slug;
            $localizations[$locale][AbstractPersister::HISTORY_URLS] = $historyUrls;
        }

        return $localizations;
    }
}
