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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sulu\Bundle\PhpcrMigrationBundle\SuluPhpcrMigrationBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Minimal test kernel for functional tests.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SuluPhpcrMigrationBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-secret',
        ]);

        // Manually configure DBAL connection (without DoctrineBundle)
        $driver = $_SERVER['DATABASE_DRIVER'] ?? $_ENV['DATABASE_DRIVER'] ?? 'pdo_mysql';
        $charset = 'pdo_pgsql' === $driver ? 'UTF8' : 'utf8mb4';

        $container->services()
            ->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([DriverManager::class, 'getConnection'])
            ->args([[
                'driver' => $driver,
                'host' => '%env(DATABASE_HOST)%',
                'port' => '%env(int:DATABASE_PORT)%',
                'user' => '%env(DATABASE_USER)%',
                'password' => '%env(DATABASE_PASSWORD)%',
                'dbname' => '%env(DATABASE_NAME)%',
                'charset' => $charset,
            ]])
            ->public();

        $container->extension('sulu_phpcr_migration', [
            'DSN' => 'dbal://default?workspace=default',
            'target' => [
                'dbal' => [
                    'connection' => 'default',
                ],
            ],
        ]);

        // Make migration command publicly accessible for tests
        $container->services()
            ->alias(\Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand::class, 'sulu_phpcr_migration.migrate_command')
            ->public();
    }
}
