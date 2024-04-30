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

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function(RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__ . '/src', __DIR__ . '/Tests']);

    $rectorConfig->phpstanConfigs([
        __DIR__ . '/phpstan.neon',
    ]);

    // basic rules
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses(false);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        LevelSetList::UP_TO_PHP_81,
    ]);
};
