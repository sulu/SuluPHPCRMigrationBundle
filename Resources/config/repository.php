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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\DryRunCollector;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Infrastructure\Repository\EntityRepository;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('sulu_phpcr_migration.dry_run_collector', DryRunCollector::class);

    $services->set('sulu_phpcr_migration.entity_repository', EntityRepository::class)
        ->args([
            new Reference('sulu_phpcr_migration.target_connection'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);
};
