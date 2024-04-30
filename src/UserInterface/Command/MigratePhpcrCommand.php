<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\UserInterface\Command;

use Sulu\Bundle\PhpcrMigrationBundle\Application\Session\SessionManager;
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

        return Command::SUCCESS;
    }
}
