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

class MainWebspaceNotConfiguredException extends \RuntimeException
{
    public function __construct(string $uuid, string $locale)
    {
        parent::__construct(\sprintf(
            'Article "%s" (locale: %s) has no main webspace and "sulu_article.default_main_webspace" is not configured for it, '
            . 'which is required when more than one webspace exists.',
            $uuid,
            $locale,
        ));
    }
}
