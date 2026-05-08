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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Application;

use Sulu\Bundle\PhpcrMigrationBundle\SuluPhpcrMigrationBundle;
use Sulu\Bundle\TestBundle\Kernel\SuluTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Minimal test kernel for functional tests.
 */
class Kernel extends SuluTestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new SuluPhpcrMigrationBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(function(ContainerBuilder $container): void {
            // Manually configure DBAL connection (without DoctrineBundle)
            $driver = $_SERVER['DATABASE_DRIVER'] ?? $_ENV['DATABASE_DRIVER'] ?? 'pdo_mysql';
            $isPgsql = 'pdo_pgsql' === $driver;
            $charset = $isPgsql ? 'UTF8' : 'utf8mb4';
            $serverVersion = $isPgsql ? '16.0' : '8.0';

            // Override the Doctrine DBAL connection that SuluTestKernel sets up via DATABASE_URL.
            // url=null prevents DoctrineBundle from using DATABASE_URL (which has MySQL-specific params).
            // server_version must be explicit — DoctrineBundle rejects empty strings for PostgreSQL.
            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'url' => null,
                    'dbname_suffix' => '',
                    'driver' => $driver,
                    'server_version' => $serverVersion,
                    'host' => '%env(DATABASE_HOST)%',
                    'port' => '%env(int:DATABASE_PORT)%',
                    'user' => '%env(DATABASE_USER)%',
                    'password' => '%env(DATABASE_PASSWORD)%',
                    'dbname' => '%env(DATABASE_NAME)%',
                    'charset' => $charset,
                ],
            ]);

            // Set default webspace parameters to test the fallback path in ArticlePersister.
            // Articles without explicit webspace in PHPCR should get these defaults applied.
            $container->setParameter('sulu_article.default_main_webspace', ['default' => 'website']);
            $container->setParameter('sulu_article.default_additional_webspaces', ['default' => ['website_2']]);

            // Configure template directories so FormMetadataFieldTypeDetector can identify
            // text_area/text_line fields and skip JSON decoding for them.
            $container->loadFromExtension('sulu_admin', [
                'templates' => [
                    'article' => [
                        'directories' => ['articles' => __DIR__ . '/sulu30/config/templates/articles'],
                    ],
                    'page' => [
                        'directories' => ['pages' => __DIR__ . '/sulu30/config/templates/pages'],
                    ],
                    'snippet' => [
                        'directories' => ['snippets' => __DIR__ . '/sulu30/config/templates/snippets'],
                    ],
                ],
            ]);

            $container->loadFromExtension('sulu_phpcr_migration', [
                'DSN' => 'dbal://default?workspace=default',
                'target' => [
                    'dbal' => [
                        'connection' => 'default',
                    ],
                ],
            ]);

            // Make migration command publicly accessible for tests
            $container->setAlias(
                \Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand::class,
                'sulu_phpcr_migration.migrate_command'
            )->setPublic(true);
        });
    }
}
