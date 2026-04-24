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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command;

use Doctrine\DBAL\Connection;
use PHPCR\NodeInterface;
use PHPCR\Query\QueryManagerInterface;
use PHPCR\SessionInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\NodeParserInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PersisterInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PersisterPool;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query\PostMigrationQueryInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\DryRunCollector;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Session\SessionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sulu:phpcr-migration:migrate', description: 'Migrate the PHPCR content repository to the SuluContentBundle.')]
class MigratePhpcrCommand extends Command
{
    private const BATCH_SIZE = 1000;

    private const LIVE_SUFFIX = '_live';

    /**
     * Document types that must be loaded in full upfront for ordering reasons.
     * Pages require parent-before-child processing for nested set inserts.
     * Snippet areas use a completely different query strategy.
     *
     * @var string[]
     */
    private const FULL_LOAD_TYPES = ['page', 'snippet_area'];

    private bool $isDryRun = false;

    /**
     * @param iterable<PostMigrationQueryInterface> $postMigrationQueries
     */
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly NodeParserInterface $nodeParser,
        private readonly PersisterPool $persisterPool,
        private readonly iterable $postMigrationQueries,
        private readonly Connection $connection,
        private readonly EntityRepositoryInterface $entityRepository,
        private readonly DryRunCollector $dryRunCollector,
        private readonly string $projectDir,
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
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Run the migration without writing to the target database. Collects errors into a JSON report.',
        );
        $this->addOption(
            'report',
            null,
            InputOption::VALUE_REQUIRED,
            'Override the JSON report path (only used with --dry-run). Default: var/phpcr-migration/dry-run-YYYYMMDD-HHMMSS.json',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->isDryRun = (bool) $input->getOption('dry-run');

        if ($this->isDryRun) {
            $this->entityRepository->setDryRun(true);
            $io->note('Running in dry-run mode. No data will be written to the target database.');
        }

        $session = $this->sessionManager->getDefaultSession();
        $liveSession = $this->sessionManager->getLiveSession();

        /** @var string|null $documentTypesArg */
        $documentTypesArg = $input->getArgument('documentTypes');
        $persisters = $documentTypesArg
            ? \array_map(fn (string $type) => $this->persisterPool->getPersister($type), \explode(',', $documentTypesArg))
            : $this->persisterPool->getPersisters();

        foreach ($persisters as $persister) {
            $documentType = $persister::getType();
            $io->title('Migrating ' . $documentType . ' documents');

            // Snippet areas only exist in default session (not in live session)
            $sessions = 'snippet_area' === $documentType ? [$session] : [$session, $liveSession];

            /** @var SessionInterface $currentSession */
            foreach ($sessions as $currentSession) {
                $sessionName = $currentSession->getWorkspace()->getName();
                $isLive = \str_ends_with($sessionName, self::LIVE_SUFFIX);
                $workspaceKey = $isLive ? \substr($sessionName, 0, -\strlen(self::LIVE_SUFFIX)) : $sessionName;

                $io->section('Migrating ' . $documentType . ' documents in ' . $sessionName);

                $queryManager = $currentSession->getWorkspace()->getQueryManager();

                if (\in_array($documentType, self::FULL_LOAD_TYPES, true)) {
                    // Pages require parent-before-child ordering for nested set inserts, so all
                    // nodes must be loaded and sorted upfront. Snippet areas use a custom query.
                    $t = \microtime(true);
                    $nodes = $this->fetchPhpcrNodes($queryManager, $documentType);
                    $io->writeln(\sprintf(
                        '  <info>Loaded %d nodes in %.2fs</info>',
                        \count($nodes),
                        \microtime(true) - $t,
                    ));

                    $progressBar = $io->createProgressBar(\count($nodes));
                    $progressBar->setFormat(ProgressBar::FORMAT_DEBUG);
                    foreach ($nodes as $node) {
                        $this->processNode($node, $persister, $isLive, $workspaceKey);
                        $progressBar->advance();
                    }
                } else {
                    // Flat document types (articles, snippets, custom_urls) use batched fetching.
                    $progressBar = $io->createProgressBar();
                    $progressBar->setFormat(ProgressBar::FORMAT_DEBUG);
                    $lastPath = null;
                    $batchCount = 0;
                    do {
                        $batchSession = $isLive
                            ? $this->sessionManager->getLiveSession()
                            : $this->sessionManager->getDefaultSession();
                        $batch = $this->fetchPhpcrNodesBatch(
                            $batchSession->getWorkspace()->getQueryManager(),
                            $documentType,
                            $lastPath,
                            self::BATCH_SIZE,
                        );
                        $batchCount = \count($batch);

                        // Capture the cursor BEFORE unsetting the batch.
                        if ($batchCount > 0) {
                            $lastPath = $batch[\array_key_last($batch)]->getPath();
                        }

                        foreach ($batch as $node) {
                            $this->processNode($node, $persister, $isLive, $workspaceKey);
                            $progressBar->advance();
                        }

                        // After the foreach, $node still holds a ref to the last Node.
                        unset($node);

                        // logout() removes the session from Jackalope's static Session::$sessionRegistry,
                        // preventing unbounded memory growth across batches.
                        $batchSession->logout();
                        unset($batchSession, $batch);
                    } while (self::BATCH_SIZE === $batchCount);
                }

                $progressBar->finish();
                $io->newLine(2);
            }
        }

        if ($this->isDryRun) {
            $io->section('Post-migration queries');
            $io->writeln('Skipped in dry-run mode.');
            $io->newLine();

            $reportPath = $this->resolveReportPath($input);
            $this->writeReport($this->dryRunCollector->toArray(), $reportPath);
            $this->dryRunCollector->printSummary($io);
            $io->writeln(\sprintf('Report written to: %s', $reportPath));

            return Command::SUCCESS;
        }

        foreach ($this->postMigrationQueries as $query) {
            $query->execute($this->connection);
        }

        $io->success('Migration completed');

        return Command::SUCCESS;
    }

    private function processNode(
        NodeInterface $node,
        PersisterInterface $persister,
        bool $isLive,
        string $workspaceKey,
    ): void {
        $documentType = $persister::getType();

        try {
            $this->persistNode($node, $persister, $isLive);
        } catch (\Throwable $e) {
            if (!$this->isDryRun) {
                throw $e;
            }

            $this->dryRunCollector->record($e, [
                'documentType' => $documentType,
                'workspace' => $workspaceKey,
                'uuid' => $node->getIdentifier(),
                'path' => $node->getPath(),
            ]);
        }

        if ($this->isDryRun) {
            $this->dryRunCollector->recordProcessed($documentType, $workspaceKey);
        }
    }

    private function resolveReportPath(InputInterface $input): string
    {
        /** @var string|null $reportOption */
        $reportOption = $input->getOption('report');
        if (null !== $reportOption && '' !== $reportOption) {
            return $reportOption;
        }

        $timestamp = (new \DateTimeImmutable())->format('Ymd-His');

        return $this->projectDir . '/var/phpcr-migration/dry-run-' . $timestamp . '.json';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeReport(array $payload, string $path): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Could not create report directory "%s".', $directory));
        }

        $json = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === \file_put_contents($path, $json)) {
            throw new \RuntimeException(\sprintf('Could not write dry-run report to "%s".', $path));
        }
    }

    private function persistNode(NodeInterface $node, PersisterInterface $persister, bool $isLive): void
    {
        $documents = $this->nodeParser->parse($node, $persister::getType());

        // Handle parsers which return multiple documents per node
        if ($this->isListOfDocuments($documents)) {
            /** @var array<string, mixed> $document */
            foreach ($documents as $document) {
                $persister->persist(document: $document, isLive: $isLive);
            }
        } else {
            $persister->persist(document: $documents, isLive: $isLive);
        }
    }

    /**
     * Fetch PHPCR nodes for document types that require full upfront loading (pages, snippet_areas).
     * Pages are sorted by depth then sulu:order to guarantee parent-before-child order.
     *
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

        $nodesArray = \iterator_to_array($result->getNodes());

        // Sort by depth (number of path segments) then by sulu:order to ensure
        // parent nodes are inserted into the nested set before their children.
        // Path is used as a final tiebreaker to guarantee deterministic ordering
        // across different databases (usort is unstable in PHP).
        \usort($nodesArray, function(NodeInterface $a, NodeInterface $b) {
            $aDepth = \count(\explode('/', $a->getPath()));
            $bDepth = \count(\explode('/', $b->getPath()));

            if ($aDepth !== $bDepth) {
                return $aDepth <=> $bDepth;
            }

            $aOrder = $a->hasProperty('sulu:order') ? $a->getProperty('sulu:order')->getValue() : 0;
            $bOrder = $b->hasProperty('sulu:order') ? $b->getProperty('sulu:order')->getValue() : 0;

            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            return $a->getPath() <=> $b->getPath();
        });

        return $nodesArray;
    }

    /**
     * Fetch a single batch of PHPCR nodes using keyset (cursor) pagination.
     *
     * @return array<NodeInterface>
     */
    private function fetchPhpcrNodesBatch(
        QueryManagerInterface $queryManager,
        string $documentType,
        ?string $lastPath,
        int $limit,
    ): array {
        if (null === $lastPath) {
            $sql = \sprintf(
                'SELECT * FROM [nt:unstructured] WHERE [jcr:mixinTypes] = "sulu:%s" ORDER BY [jcr:path]',
                $documentType,
            );
        } else {
            $sql = \sprintf(
                'SELECT * FROM [nt:unstructured] WHERE [jcr:mixinTypes] = "sulu:%s" AND [jcr:path] > "%s" ORDER BY [jcr:path]',
                $documentType,
                \addslashes($lastPath),
            );
        }

        $query = $queryManager->createQuery($sql, 'JCR-SQL2');
        $query->setLimit($limit);

        return \iterator_to_array($query->execute()->getNodes());
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
