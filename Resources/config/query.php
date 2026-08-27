<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query\MigratePermissionContextsQuery;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query\UpdateAccessControlEntityClassQuery;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('sulu_phpcr_migration.update_access_control_entity_class_query', UpdateAccessControlEntityClassQuery::class)
        ->tag('sulu_phpcr_migration.post_migration_query');

    $services->set('sulu_phpcr_migration.migrate_permission_contexts_query', MigratePermissionContextsQuery::class)
        ->args([
            new Reference('sulu_phpcr_migration.entity_repository'),
        ])
        ->tag('sulu_phpcr_migration.post_migration_query');
};
