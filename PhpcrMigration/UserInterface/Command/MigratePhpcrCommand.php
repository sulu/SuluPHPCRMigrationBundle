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

use Doctrine\DBAL\Connection;
use PHPCR\NodeInterface;
use PHPCR\Query\QueryManagerInterface;
use PHPCR\SessionInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\NodeParserInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PersisterPool;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query\PostMigrationQueryInterface;
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
    /**
     * @param iterable<PostMigrationQueryInterface> $postMigrationQueries
     */
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly NodeParserInterface $nodeParser,
        private readonly PersisterPool $persisterPool,
        private readonly iterable $postMigrationQueries,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $types = [];
        foreach ($this->persisterPool->getPersisters() as $persister) {
            $types[] = $persister::getType();
        }

        $this->addArgument(
            'documentTypes',
            InputArgument::OPTIONAL,
            \sprintf('The document type(s) to migrate. Available: %s', \implode(', ', $types)),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->sessionManager->getDefaultSession();
        $liveSession = $this->sessionManager->getLiveSession();

        /** @var string|null $documentTypesArg */
        $documentTypesArg = $input->getArgument('documentTypes');
        $persisters = $documentTypesArg
            ? \array_map(fn (string $type) => $this->persisterPool->getPersister($type), \explode(',', $documentTypesArg))
            : $this->persisterPool->getPersisters();

        $io = new SymfonyStyle($input, $output);
        foreach ($persisters as $persister) {
            $documentType = $persister::getType();
            $io->title('Migrating ' . $documentType . ' documents');

            // Snippet areas only exist in default session (not in live session)
            $sessions = 'snippet_area' === $documentType ? [$session] : [$session, $liveSession];

            /** @var SessionInterface $currentSession */
            foreach ($sessions as $currentSession) {
                $sessionName = $currentSession->getWorkspace()->getName();
                $io->section('Migrating ' . $documentType . ' documents in ' . $sessionName);

                $queryManager = $session->getWorkspace()->getQueryManager();
                $nodes = $this->fetchPhpcrNodes($queryManager, $documentType);

                $progressBar = $io->createProgressBar(\iterator_count($nodes));
                $progressBar->setFormat(ProgressBar::FORMAT_DEBUG);
                foreach ($nodes as $node) {
                    $documents = $this->nodeParser->parse($node, $documentType);

                    // Handle parsers which return multiple documents per node
                    if ($this->isListOfDocuments($documents)) {
                        /** @var array<string, mixed> $document */
                        foreach ($documents as $document) {
                            $persister->persist(
                                document: $document,
                                isLive: \str_ends_with($sessionName, '_live'),
                            );
                        }
                    } else {
                        $persister->persist(
                            document: $documents,
                            isLive: \str_ends_with($sessionName, '_live'),
                        );
                    }
                    $progressBar->advance();
                }
                $progressBar->finish();
                $io->newLine(2);
            }
        }

        foreach ($this->postMigrationQueries as $query) {
            $query->execute($this->connection);
        }

        $io->success('Migration completed');

        return Command::SUCCESS;
    }

    /**
     * @return array<NodeInterface>
     */
    private function fetchPhpcrNodes(QueryManagerInterface $queryManager, string $documentType): array
    {
        // Special handling for snippet areas (stored as webspace properties, not documents)
        if ('snippet_area' === $documentType) {
            return $this->fetchWebspaceNodes($queryManager);
        }

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

    /**
     * Check if the parsed result is a list of documents vs a single document.
     *
     * A list of documents has sequential numeric keys (0, 1, 2...) with each element being a document array.
     * A single document has string keys (uuid, title, etc.) at the top level.
     *
     * @param array<int|string, mixed> $documents
     */
    private function isListOfDocuments(array $documents): bool
    {
        if ([] === $documents) {
            return false;
        }

        // Check if array is a list (sequential numeric keys starting at 0)
        if (!\array_is_list($documents)) {
            return false;
        }

        // Check if first element is an array (a document)
        $firstElement = \reset($documents);

        return \is_array($firstElement);
    }

    /**
     * Fetch webspace nodes for snippet area migration.
     *
     * @return array<NodeInterface>
     */
    private function fetchWebspaceNodes(QueryManagerInterface $queryManager): array
    {
        // Query for all nodes under /cmf that are webspace nodes (depth = 2)
        $sql = 'SELECT * FROM [nt:unstructured] WHERE ISCHILDNODE([/cmf])';
        $query = $queryManager->createQuery($sql, 'JCR-SQL2');
        $result = $query->execute();

        $nodes = $result->getNodes();
        $nodesArray = \iterator_to_array($nodes);

        // Filter to only webspace nodes that have snippet area properties
        return \array_filter($nodesArray, function(NodeInterface $node) {
            try {
                $properties = $node->getProperties('settings:snippets-*');
                $propertiesArray = \iterator_to_array($properties);

                return [] !== $propertiesArray;
                // @phpstan-ignore-next-line fail-loud: intentional catch-all for missing properties
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
