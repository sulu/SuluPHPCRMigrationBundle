<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Session\SessionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sulu:phpcr-migration:migrate', description: 'Migrate the PHPCR content repository to the SuluContentBundle.')]
class MigratePhpcrCommand extends Command
{
    public function __construct(private readonly SessionManager $sessionManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->sessionManager->getDefaultSession();
        $liveSession = $this->sessionManager->getLiveSession();

        $queryManager = $session->getWorkspace()->getQueryManager();
        $sql =
            sprintf(
                'SELECT * FROM [nt:unstructured] as document WHERE [jcr:mixinTypes] = "sulu:page" AND (isdescendantnode(document, "/cmf/%s/contents") OR issamenode(document, "/cmf/%s/contents"))',
                'sulu',
                'sulu'
            );;
        $query = $queryManager->createQuery($sql, 'JCR-SQL2');
        $result = $query->execute();

        $documents = [];
        foreach ($result->getNodes() as $node) {
            $document = [];

            foreach ($node->getProperties() as $property) {
                $name = $property->getName();
                $value = $property->getValue();
                $document[$name] = $value;
            }

            $documents[$node->getIdentifier()] = $document;
        }


        return Command::SUCCESS;
    }
}
