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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\NodeParserInterface;
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
        private readonly NodeParserInterface $nodeParser,
        private readonly PersisterPool $persisterPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('documentTypes', InputArgument::OPTIONAL, 'The document type to migrate. (e.g. snippet, page, article)', 'page,article,snippet');
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

            /** @var SessionInterface $currentSession */
            foreach ([$session, $liveSession] as $currentSession) {
                $io->section('Migrating ' . $documentType . ' documents in ' . $currentSession->getWorkspace()->getName());
                $nodes = $this->fetchPhpcrNodes($currentSession, $documentType);
                $progressBar = $io->createProgressBar(\iterator_count($nodes));
                $progressBar->setFormat(ProgressBar::FORMAT_DEBUG);
                foreach ($nodes as $node) {
                    $document = $this->nodeParser->parse($node);
                    $persister->persist(
                        document: $document,
                        isLive: \str_ends_with($currentSession->getWorkspace()->getName(), '_live'),
                    );
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
     * @return array<NodeInterface>
     */
    private function fetchPhpcrNodes(SessionInterface $session, string $documentType): array
    {
        $queryManager = $session->getWorkspace()->getQueryManager();

        $wheres = [
            \sprintf('[jcr:mixinTypes] = "sulu:%s"', $documentType),
        ];

        if ('page' === $documentType) {
            $wheres[] = '[jcr:mixinTypes] = "sulu:home"';
        }

        $sql = \sprintf(
            'SELECT * FROM [nt:unstructured] as document WHERE %s',
            \implode(' OR ', $wheres),
        );
        $query = $queryManager->createQuery($sql, 'JCR-SQL2');
        $result = $query->execute();

        $nodes = $result->getNodes();

        // Convert to array for sorting
        $nodesArray = \iterator_to_array($nodes);

        // Sort by depth (number of path segments) then by sulu:order
        \usort($nodesArray, function(NodeInterface $a, NodeInterface $b) {
            $aDepth = \count(\explode('/', $a->getPath()));
            $bDepth = \count(\explode('/', $b->getPath()));

            if ($aDepth !== $bDepth) {
                return $aDepth <=> $bDepth;
            }

            $aOrder = $a->hasProperty('sulu:order') ? $a->getProperty('sulu:order')->getValue() : 0;
            $bOrder = $b->hasProperty('sulu:order') ? $b->getProperty('sulu:order')->getValue() : 0;

            return $aOrder <=> $bOrder;
        });

        return $nodesArray;
    }
}
