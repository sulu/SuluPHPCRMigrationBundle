<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception;

class PersisterNotFoundException extends \RuntimeException
{
    public function __construct(string $type)
    {
        parent::__construct('Persister for type "' . $type . '" not found.');
    }
}
