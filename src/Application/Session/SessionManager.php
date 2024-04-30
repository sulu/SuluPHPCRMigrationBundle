<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\Application\Session;

use Doctrine\DBAL\Connection;
use Jackalope\RepositoryFactoryDoctrineDBAL;
use Jackalope\RepositoryFactoryJackrabbit;
use PHPCR\SessionInterface;
use PHPCR\SimpleCredentials;

class SessionManager
{
    private readonly string $workspace;
    private readonly string $workspaceLive;

    /**
     * @param array{
     *      connection: array{
     *          type: 'dbal' | 'jackrabbit',
     *          name?: string,
     *          url?: string,
     *          user?: string,
     *          password?: string
     *      },
     *      workspace: array{
     *          default: string,
     *          live: string
     *      }
     *  } $configuration
     */
    public function __construct(
        private array $configuration,
        private readonly ?Connection $connection = null,
    ) {
        $this->workspace = $configuration['workspace']['default'];
        $this->workspaceLive = $configuration['workspace']['live'];
    }

    public function getDefaultSession(): SessionInterface
    {
        return $this->getSession($this->workspace);
    }

    public function getLiveSession(): SessionInterface
    {
        return $this->getSession($this->workspaceLive);
    }

    private function getSession(string $workspace): SessionInterface
    {
        $factory = $this->connection instanceof Connection ? new RepositoryFactoryDoctrineDBAL() : new RepositoryFactoryJackrabbit();
        $repository = $factory->getRepository(\array_filter([
            'jackalope.doctrine_dbal_connection' => $this->connection,
            'jackalope.jackrabbit_uri' => $this->configuration['connection']['url'] ?? null,
        ]));

        $credentials = new SimpleCredentials(
            $this->configuration['connection']['user'] ?? 'dummy',
            $this->configuration['connection']['password'] ?? 'dummy'
        );

        return $repository->login($credentials, $workspace);
    }
}
