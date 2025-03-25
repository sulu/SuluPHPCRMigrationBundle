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

class ChainNodeParser implements NodeParserInterface
{
    /**
     * @param NodeParserInterface[] $nodeParsers
     */
    public function __construct(private readonly iterable $nodeParsers)
    {
    }

    public function parse(NodeInterface $node): array
    {
        $document = [];
        foreach ($this->nodeParsers as $nodeParser) {
            $document = \array_merge_recursive($document, $nodeParser->parse($node));
        }

        return $document;
    }
}
