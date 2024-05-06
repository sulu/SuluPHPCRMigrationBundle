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

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SuluPhpcrMigrationBundle extends AbstractBundle
{
    /**
     * @param mixed[] $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $loader = new XmlFileLoader($builder, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('session.xml');
        $loader->load('command.xml');
        $loader->load('parser.xml');
        $loader->load('persister.xml');
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $rootNode
            ->children()
                ->scalarNode('DSN')->isRequired()->end()
                ->arrayNode('target')
                    ->children()
                        ->arrayNode('dbal')
                            ->children()
                                ->scalarNode('connection')->isRequired()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{'DSN': string}[] $config */
        $config = $builder->getExtensionConfig('sulu_phpcr_migration');

        $dsn = $config[0]['DSN'];
        $builder->setParameter('sulu_phpcr_migration.dsn', $dsn);

        $targetConnectionName = $config[0]['target']['dbal']['connection'];
        $builder->setAlias('sulu_phpcr_migration.target_connection', \sprintf('doctrine.dbal.%s_connection', $targetConnectionName));

        $configuration = $this->getConnectionConfiguration($dsn);
        $builder->setParameter('sulu_phpcr_migration.configuration', $configuration);

        if ('dbal' === $configuration['connection']['type'] && $name = ($configuration['connection']['name'] ?? null)) {
            $builder->setAlias('sulu_phpcr_migration.connection', \sprintf('doctrine.dbal.%s_connection', $name));

            return;
        }
    }

    /**
     * @return array{
     *     connection: array{
     *         type: string,
     *         name?: string,
     *         url?: string,
     *         user?: string|null,
     *         password?: string|null
     *     },
     *     workspace: array{
     *         default: string,
     *         live: string
     *     }
     * }
     */
    private function getConnectionConfiguration(string $dsn): array
    {
        /** @var array{
         *     scheme: string,
         *     host?: string,
         *     port?: string,
         *     path?: string,
         *     query: string,
         *     user?: string,
         *     pass?: string
         * } $parts
         */
        $parts = \parse_url($dsn);
        \parse_str($parts['query'], $query);

        /** @var string|null $workspace */
        $workspace = $query['workspace'] ?? '';
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

        if ('dbal' === $parts['scheme'] && ($host = $parts['host'] ?? null)) {
            $result['connection']['name'] = $host;

            return $result;
        }

        $result['connection']['url'] = \sprintf(
            '%s:%s%s%s',
            $parts['host'] ?? '',
            $parts['port'] ?? '',
            $parts['path'] ?? '',
            $query ? '?' . \http_build_query($query) : '',
        );
        $result['connection']['user'] = $parts['user'] ?? null;
        $result['connection']['password'] = $parts['pass'] ?? null;

        return $result;
    }
}
