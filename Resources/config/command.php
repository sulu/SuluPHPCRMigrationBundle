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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    // Command
    $services->set('sulu_phpcr_migration.migrate_command', MigratePhpcrCommand::class)
        ->args([
            new Reference('sulu_phpcr_migration.session_manager'),
            new Reference('sulu_phpcr_migration.chain_node_parser'),
            new Reference('sulu_phpcr_migration.persister_pool'),
            new TaggedIteratorArgument('sulu_phpcr_migration.post_migration_query'),
            new Reference('doctrine.dbal.default_connection'),
            new Reference('sulu_phpcr_migration.entity_repository'),
            new Reference('sulu_phpcr_migration.dry_run_collector'),
            '%kernel.project_dir%',
        ])
        ->tag('console.command');
};
