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

use PHPCR\NodeInterface;
use PHPCR\SessionInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\NodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PersisterPool;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Session\SessionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sulu:phpcr-migration:migrate', description: 'Migrate the PHPCR content repository to the SuluContentBundle.')]
class MigratePhpcrCommand extends Command
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly NodeParser $nodeParser,
        private readonly PersisterPool $persisterPool
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('documentTypes', InputArgument::OPTIONAL, 'The document type to migrate. (e.g. snippet, page, article)', 'article');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->sessionManager->getDefaultSession();
        $liveSession = $this->sessionManager->getLiveSession();

        /** @var string $documentTypes */
        $documentTypes = $input->getArgument('documentTypes');
        $documentTypes = \explode(',', $documentTypes);

        $io = new SymfonyStyle($input, $output);
        foreach ($documentTypes as $documentType) {
            $io->title('Migrating ' . $documentType . ' documents');
            $persister = $this->persisterPool->getPersister($documentType);

            /** @var SessionInterface $session */
            foreach ([$session, $liveSession] as $session) {
                $io->section('Migrating ' . $documentType . ' documents in ' . $session->getWorkspace()->getName());
                $nodes = $this->fetchPhpcrNodes($session, $documentType);
                $progressBar = $io->createProgressBar(\iterator_count($nodes));
                $progressBar->setFormat(ProgressBar::FORMAT_DEBUG);
                foreach ($nodes as $node) {
                    $document = $this->nodeParser->parse($node);
                    $persister->persist($document, \str_ends_with($session->getWorkspace()->getName(), '_live'));
                    $progressBar->advance();
                }
                $progressBar->finish();
                $io->newLine(2);
            }
        }

        $io->success('Migration completed');

        return Command::SUCCESS;
    }

    /**
     * @return \Traversable<NodeInterface>
     */
    private function fetchPhpcrNodes(SessionInterface $session, string $documentType): \Traversable
    {
        $queryManager = $session->getWorkspace()->getQueryManager();

        $sql = \sprintf(
            'SELECT * FROM [nt:unstructured] as document WHERE [jcr:mixinTypes] = "sulu:%s"',
            $documentType
        );
        $query = $queryManager->createQuery($sql, 'JCR-SQL2');
        $result = $query->execute();

        return $result->getNodes();
    }
}
