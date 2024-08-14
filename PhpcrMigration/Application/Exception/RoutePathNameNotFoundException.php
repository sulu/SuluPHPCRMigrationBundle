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

class RoutePathNameNotFoundException extends \RuntimeException
{
    public function __construct(string $uuid, string $locale)
    {
        parent::__construct(
            \sprintf(
                'RoutePathName not found for uuid "%s" and locale "%s". Before migrating to the ArticleBundle 3.0 you must update to ArticleBundle 2.6 and execute all phpcr migrations.',
                $uuid,
                $locale
            )
        );
    }
}
