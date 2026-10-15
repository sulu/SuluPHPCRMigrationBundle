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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception;

class UniqueKeyViolationException extends \RuntimeException
{
    /**
     * @param list<string> $values
     */
    public function __construct(string $table, string $key, array $values)
    {
        parent::__construct(\sprintf(
            "Duplicate entry '%s' for key '%s.%s'",
            \implode('-', $values),
            $table,
            $key,
        ));
    }
}
