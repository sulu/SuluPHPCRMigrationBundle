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

class TitleTooLongException extends \RuntimeException
{
    public function __construct(string $title, string $uuid, ?string $locale)
    {
        parent::__construct(\sprintf(
            'Title "%s" for document "%s" (locale: %s) exceeds the maximum length of 191 characters.',
            $title,
            $uuid,
            $locale ?? 'null',
        ));
    }
}
