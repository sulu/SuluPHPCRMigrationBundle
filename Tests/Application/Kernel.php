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

        $loader->load(__DIR__ . '/config/services.yaml');

        $loader->load(function(ContainerBuilder $container): void {
            // SuluArticleBundle declares default_main_webspace / default_additional_webspaces
            // as configurable but ships no defaults. These values exercise ArticlePersister's
            // fallback path for articles without an explicit webspace in PHPCR.
            $container->setParameter('sulu_article.default_main_webspace', ['default' => 'website']);
            $container->setParameter('sulu_article.default_additional_webspaces', ['default' => ['website_2']]);

            // sulu/sulu auto-prepends '%kernel.project_dir%/config/templates/...' but our
            // templates live under sulu30. Point the detector at them explicitly.
            $container->loadFromExtension('sulu_admin', [
                'templates' => [
                    'article' => ['directories' => ['articles' => __DIR__ . '/sulu30/config/templates/articles']],
                    'page' => ['directories' => ['pages' => __DIR__ . '/sulu30/config/templates/pages']],
                    'snippet' => ['directories' => ['snippets' => __DIR__ . '/sulu30/config/templates/snippets']],
                ],
            ]);

            $container->loadFromExtension('sulu_phpcr_migration', [
                'DSN' => 'dbal://default?workspace=default',
                'target' => ['dbal' => ['connection' => 'default']],
            ]);
        });
    }
}
