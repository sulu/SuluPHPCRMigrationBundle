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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

/**
 * Sulu 2.x stored creator and changer as plain integers, so they can reference deleted users.
 * Sulu 3.0 enforces foreign keys with ON DELETE SET NULL, which nulling the reference mirrors.
 *
 * @property EntityRepositoryInterface $entityRepository
 */
trait NullsDeletedUsersTrait
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function nullDeletedUsers(array $data): array
    {
        foreach (['idUsersCreator', 'idUsersChanger'] as $key) {
            if (isset($data[$key]) && !$this->entityRepository->exists('se_users', ['id' => $data[$key]])) {
                $data[$key] = null;
            }
        }

        return $data;
    }
}
