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

class UnsupportedDocumentTypeException extends \RuntimeException
{
    /**
     * @param array<string> $types
     */
    public function __construct(array $types)
    {
        parent::__construct(\sprintf('Unsupported document type(s) "%s"', \implode('", "', $types)));
    }
}
