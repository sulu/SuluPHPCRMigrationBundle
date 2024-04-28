<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SuluPhpcrMigrationBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $loader = new XmlFileLoader($builder, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('session.xml');
        $loader->load('command.xml');
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('DSN')->isRequired()->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $config = $builder->getExtensionConfig('sulu_phpcr_migration');

        /** @var string $dsn */
        $dsn = $config[0]['DSN'];
        $builder->setParameter('sulu_phpcr_migration.dsn', $dsn);

        $configuration = $this->getConnectionConfiguration($dsn);
        $builder->setParameter('sulu_phpcr_migration.configuration', $configuration);

        if ('dbal' === $configuration['connection']['type']) {
            $builder->setAlias('sulu_phpcr_migration.connection', \sprintf('doctrine.dbal.%s_connection', $configuration['connection']['name']));

            return;
        }
    }

    /**
     * @return array{
     *     connection: array{
     *         type: 'dbal' | 'jackrabbit',
     *         name?: string,
     *         url?: string,
     *         user?: string,
     *         password?: string
     *     },
     *     workspace: array{
     *         default: string,
     *         live: string
     *     }
     * }
     */
    private function getConnectionConfiguration(string $dsn): array
    {
        $parts = \parse_url($dsn);
        \parse_str($parts['query'], $query);

        $workspace = $query['workspace'];
        unset($query['workspace']);

        if (!$workspace) {
            throw new \InvalidArgumentException('Workspace is missing in DSN');
        }

        $result = [
            'connection' => [
                'type' => $parts['scheme'],
            ],
            'workspace' => [
                'default' => $workspace,
                'live' => $workspace . '_live',
            ],
        ];

        if ('dbal' === $parts['scheme']) {
            $result['connection']['name'] = $parts['host'];

            return $result;
        }

        $result['url'] = \sprintf(
            '%s:%s/%s%s',
            $parts['host'],
            $parts['port'],
            $parts['path'],
            $query ? '?' . \http_build_query($query) : '',
        );
        $result['user'] = $parts['user'] ?? null;
        $result['password'] = $parts['pass'] ?? null;

        return $result;
    }
}
