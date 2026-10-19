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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

/**
 * Migrates permission contexts from Sulu 2.6 to Sulu 3.0 structure.
 *
 * Updates old permission contexts to new naming:
 * - sulu.modules.articles* → sulu.article.articles*
 * - sulu.global.snippets → sulu.snippet.snippets
 * - sulu.webspaces.{webspace}.default-snippets → sulu.webspaces.{webspace}.snippet-areas
 */
class MigratePermissionContextsQuery implements PostMigrationQueryInterface
{
    private const ARTICLE_OLD_PREFIX = 'sulu.modules.articles';
    private const ARTICLE_NEW_PREFIX = 'sulu.article.articles';

    private const SNIPPET_OLD_CONTEXT = 'sulu.global.snippets';
    private const SNIPPET_NEW_CONTEXT = 'sulu.snippet.snippets';

    private const WEBSPACE_DEFAULT_SNIPPETS_PREFIX = 'sulu.webspaces.';
    private const WEBSPACE_DEFAULT_SNIPPETS_SUFFIX = '.default-snippets';
    private const WEBSPACE_SNIPPET_AREAS_SUFFIX = '.snippet-areas';

    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
    ) {
    }

    public function execute(Connection $connection): void
    {
        $this->migrateArticlePermissions($connection);
        $this->migrateSnippetPermissions($connection);
        $this->migrateWebspaceSnippetAreaPermissions($connection);
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
        $connection->executeStatement(
            'UPDATE se_permissions SET context = :newContext WHERE context = :oldContext',
            [
                'newContext' => self::SNIPPET_NEW_CONTEXT,
                'oldContext' => self::SNIPPET_OLD_CONTEXT,
            ]
        );
    }

    private function migrateWebspaceSnippetAreaPermissions(Connection $connection): void
    {
        $oldPermissions = $connection->fetchAllAssociative(
            'SELECT id, context, idRoles AS role_id FROM se_permissions WHERE context LIKE :pattern',
            ['pattern' => self::WEBSPACE_DEFAULT_SNIPPETS_PREFIX . '%' . self::WEBSPACE_DEFAULT_SNIPPETS_SUFFIX]
        );

        foreach ($oldPermissions as $oldPermission) {
            $oldContext = $oldPermission['context'];

            if (!\is_string($oldContext)) {
                continue;
            }

            $newContext = \substr($oldContext, 0, -\strlen(self::WEBSPACE_DEFAULT_SNIPPETS_SUFFIX))
                . self::WEBSPACE_SNIPPET_AREAS_SUFFIX;

            $exists = $this->entityRepository->exists('se_permissions', [
                'context' => $newContext,
                'idRoles' => $oldPermission['role_id'],
            ]);

            if ($exists) {
                continue;
            }

            $connection->executeStatement(
                'UPDATE se_permissions SET context = :newContext WHERE id = :id',
                [
                    'newContext' => $newContext,
                    'id' => $oldPermission['id'],
                ]
            );
        }
    }
}
