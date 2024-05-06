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

interface PersisterInterface
{
    public function persist(array $document, bool $isLive): void;

    public function supports(array $document): bool;

    public static function getType(): string;

    public static function getEntityTableName(): string;

    public static function getDimensionContentTableName(): string;
}
