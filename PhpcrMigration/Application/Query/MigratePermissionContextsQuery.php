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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query;

use Doctrine\DBAL\Connection;

/**
 * Migrates permission contexts from Sulu 2.6 to Sulu 3.0 structure.
 *
 * Updates old permission contexts to new naming:
 * - sulu.modules.articles* → sulu.article.articles*
 * - sulu.global.snippets → sulu.snippet.snippets + sulu.snippet.snippet_areas
 */
class MigratePermissionContextsQuery implements PostMigrationQueryInterface
{
    private const ARCHIVE_PERMISSION = 16;

    private const ARTICLE_OLD_PREFIX = 'sulu.modules.articles';
    private const ARTICLE_NEW_PREFIX = 'sulu.article.articles';

    private const SNIPPET_OLD_CONTEXT = 'sulu.global.snippets';
    private const SNIPPET_NEW_CONTEXT = 'sulu.snippet.snippets';
    private const SNIPPET_AREA_CONTEXT = 'sulu.snippet.snippet_areas';

    public function execute(Connection $connection): void
    {
        $this->migrateArticlePermissions($connection);
        $this->migrateSnippetPermissions($connection);
    }

    private function migrateArticlePermissions(Connection $connection): void
    {
        $oldContexts = $connection->fetchFirstColumn(
            'SELECT DISTINCT context FROM se_permissions WHERE context LIKE :pattern',
            ['pattern' => self::ARTICLE_OLD_PREFIX . '%']
        );

        foreach ($oldContexts as $oldContext) {
            if (!\is_string($oldContext)) {
                continue;
            }

            $newContext = \str_replace(
                self::ARTICLE_OLD_PREFIX,
                self::ARTICLE_NEW_PREFIX,
                $oldContext
            );

            $connection->executeStatement(
                'UPDATE se_permissions SET context = :newContext WHERE context = :oldContext',
                [
                    'newContext' => $newContext,
                    'oldContext' => $oldContext,
                ]
            );
        }
    }

    private function migrateSnippetPermissions(Connection $connection): void
    {
        // Use lowercase alias to ensure consistent array key regardless of PDO/MySQL case handling
        $existingPermissions = $connection->fetchAllAssociative(
            'SELECT permissions, idRoles AS role_id FROM se_permissions WHERE context = :oldContext',
            ['oldContext' => self::SNIPPET_OLD_CONTEXT]
        );

        $connection->executeStatement(
            'UPDATE se_permissions SET context = :newContext WHERE context = :oldContext',
            [
                'newContext' => self::SNIPPET_NEW_CONTEXT,
                'oldContext' => self::SNIPPET_OLD_CONTEXT,
            ]
        );

        foreach ($existingPermissions as $permission) {
            $roleId = $permission['role_id'];

            $exists = $connection->fetchOne(
                'SELECT COUNT(*) FROM se_permissions WHERE context = :areaContext AND idRoles = :idRoles',
                [
                    'areaContext' => self::SNIPPET_AREA_CONTEXT,
                    'idRoles' => $roleId,
                ]
            );

            if (!$exists) {
                $connection->insert('se_permissions', [
                    'context' => self::SNIPPET_AREA_CONTEXT,
                    'permissions' => self::ARCHIVE_PERMISSION,
                    'idRoles' => $roleId,
                ]);
            }
        }
    }
}
