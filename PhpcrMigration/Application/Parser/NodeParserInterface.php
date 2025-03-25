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

interface NodeParserInterface
{
    /**
     * @return array<string, mixed>
     */
    public function parse(NodeInterface $node): array;
}
