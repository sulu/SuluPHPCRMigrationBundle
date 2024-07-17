<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\PersisterNotFoundException;

class PersisterPool
{
    /**
     * @param iterable<PersisterInterface> $persisters
     */
    public function __construct(private readonly iterable $persisters)
    {
    }

    public function getPersister(string $type): PersisterInterface
    {
        foreach ($this->persisters as $persister) {
            if ($persister::getType() === $type) {
                return $persister;
            }
        }

        throw new PersisterNotFoundException($type);
    }
}
