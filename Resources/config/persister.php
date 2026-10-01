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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\ArticlePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\CustomUrlPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PersisterPool;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\SnippetAreaPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\SnippetPersister;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('sulu_phpcr_migration.page_persister', PagePersister::class)
        ->args([
            new Reference('property_accessor'),
            new Reference('sulu_phpcr_migration.entity_repository'),
        ])
        ->tag('sulu_phpcr_migration.persister', ['type' => 'page']);

    $services->set('sulu_phpcr_migration.snippet_persister', SnippetPersister::class)
        ->args([
            new Reference('property_accessor'),
            new Reference('sulu_phpcr_migration.entity_repository'),
        ])
        ->tag('sulu_phpcr_migration.persister', ['type' => 'snippet']);

    $services->set('sulu_phpcr_migration.article_persister', ArticlePersister::class)
        ->args([
            new Reference('property_accessor'),
            new Reference('sulu_phpcr_migration.entity_repository'),
            '%sulu_article.default_main_webspace%',
            '%sulu_article.default_additional_webspaces%',
        ])
        ->tag('sulu_phpcr_migration.persister', ['type' => 'article']);

    $services->set('sulu_phpcr_migration.custom_url_persister', CustomUrlPersister::class)
        ->args([
            new Reference('sulu_phpcr_migration.entity_repository'),
        ])
        ->tag('sulu_phpcr_migration.persister', ['type' => 'custom_url']);

    $services->set('sulu_phpcr_migration.snippet_area_persister', SnippetAreaPersister::class)
        ->args([
            new Reference('sulu_phpcr_migration.entity_repository'),
        ])
        ->tag('sulu_phpcr_migration.persister', ['type' => 'snippet_area']);

    $services->set('sulu_phpcr_migration.persister_pool', PersisterPool::class)
        ->args([
            tagged_iterator('sulu_phpcr_migration.persister', indexAttribute: 'type'),
        ]);
};
