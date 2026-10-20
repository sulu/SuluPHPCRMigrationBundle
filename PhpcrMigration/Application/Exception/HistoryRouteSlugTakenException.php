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

class HistoryRouteSlugTakenException extends \RuntimeException
{
    public function __construct(string $slug, string $uuid, string $locale, string $owner)
    {
        parent::__construct(\sprintf(
            'History route "%s" of document "%s" (locale: %s) cannot be migrated, its slug is used by the published "%s". Change one of the urls in Sulu 2.6 and run the migration again.',
            $slug,
            $uuid,
            $locale,
            $owner,
        ));
    }
}
