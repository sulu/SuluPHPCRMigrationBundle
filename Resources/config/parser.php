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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector\FormMetadataFieldTypeDetector;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor\LocaleExtractor;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\ArticleNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\ChainNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\CustomUrlNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\PageNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\PropertyNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\SnippetAreaNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Resolver\PropertyValueResolver;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('sulu_phpcr_migration.locale_extractor', LocaleExtractor::class);

    $services->set('sulu_phpcr_migration.field_type_detector', FormMetadataFieldTypeDetector::class)
        ->args([
            new Reference('sulu_admin.form_metadata_provider'),
            new Reference('sulu_phpcr_migration.locale_extractor'),
            '%sulu_admin.templates.configuration%',
        ]);

    $services->set('sulu_phpcr_migration.property_value_resolver', PropertyValueResolver::class)
        ->args([
            new Reference('sulu_phpcr_migration.field_type_detector'),
        ]);

    $services->set('sulu_phpcr_migration.node_parser', PropertyNodeParser::class)
        ->args([
            new Reference('property_accessor'),
            new Reference('sulu_phpcr_migration.locale_extractor'),
            new Reference('sulu_phpcr_migration.property_value_resolver'),
        ])
        ->tag('sulu_phpcr_migration.node_parser');

    $services->set('sulu_phpcr_migration.page_parser', PageNodeParser::class)
        ->args([
            new Reference('sulu_phpcr_migration.locale_extractor'),
        ])
        ->tag('sulu_phpcr_migration.node_parser');

    $services->set('sulu_phpcr_migration.article_parser', ArticleNodeParser::class)
        ->args([
            new Reference('sulu_phpcr_migration.entity_repository'),
            new Reference('sulu_phpcr_migration.locale_extractor'),
        ])
        ->tag('sulu_phpcr_migration.node_parser');

    $services->set('sulu_phpcr_migration.custom_url_parser', CustomUrlNodeParser::class)
        ->args([
            new Reference('sulu_phpcr_migration.node_parser'),
        ])
        ->tag('sulu_phpcr_migration.node_parser');

    $services->set('sulu_phpcr_migration.snippet_area_parser', SnippetAreaNodeParser::class)
        ->tag('sulu_phpcr_migration.node_parser');

    $services->set('sulu_phpcr_migration.chain_node_parser', ChainNodeParser::class)
        ->args([
            new TaggedIteratorArgument('sulu_phpcr_migration.node_parser'),
        ]);
};
