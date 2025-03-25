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

        return [
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
}
