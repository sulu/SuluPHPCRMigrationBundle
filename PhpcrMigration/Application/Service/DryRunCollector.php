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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

class DryRunCollector
{
    /**
     * @var list<array{
     *     documentType: string,
     *     workspace: string,
     *     uuid: string,
     *     path: string,
     *     exceptionClass: string,
     *     message: string,
     *     timestamp: string,
     * }>
     */
    private array $errors = [];

    /**
     * @var array<string, int> keyed by "$documentType|$workspace"
     */
    private array $processedNodes = [];

    public function recordProcessed(string $documentType, string $workspace): void
    {
        $key = $this->buildKey($documentType, $workspace);
        $this->processedNodes[$key] = ($this->processedNodes[$key] ?? 0) + 1;
    }

    /**
     * @param array{documentType: string, workspace: string, uuid: string, path: string} $context
     */
    public function record(\Throwable $e, array $context): void
    {
        $fqcn = $e::class;
        $lastSeparator = \strrpos($fqcn, '\\');

        $this->errors[] = [
            'documentType' => $context['documentType'],
            'workspace' => $context['workspace'],
            'uuid' => $context['uuid'],
            'path' => $context['path'],
            'exceptionClass' => false === $lastSeparator ? $fqcn : \substr($fqcn, $lastSeparator + 1),
            'message' => $e->getMessage(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function buildKey(string $documentType, string $workspace): string
    {
        return $documentType . '|' . $workspace;
    }

    /**
     * @return array{
     *     dryRunAt: string,
     *     totalNodes: int,
     *     totalErrors: int,
     *     errors: list<array<string, mixed>>,
     * }
     */
    public function toArray(): array
    {
        return [
            'dryRunAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'totalNodes' => (int) \array_sum($this->processedNodes),
            'totalErrors' => \count($this->errors),
            'errors' => $this->errors,
        ];
    }

    public function printSummary(SymfonyStyle $io): void
    {
        $io->writeln('');
        $io->writeln('Dry run complete — NO data was written to the database.');
        $io->writeln('=======================================================');

        $grouped = [];
        foreach ($this->errors as $error) {
            $key = $this->buildKey($error['documentType'], $error['workspace']);
            $grouped[$key] ??= [];
            $grouped[$key][$error['exceptionClass']] = ($grouped[$key][$error['exceptionClass']] ?? 0) + 1;
        }

        $allKeys = \array_unique(\array_merge(\array_keys($this->processedNodes), \array_keys($grouped)));
        \sort($allKeys);

        foreach ($allKeys as $key) {
            [$documentType, $workspace] = \explode('|', $key, 2);
            $nodeCount = $this->processedNodes[$key] ?? 0;
            $errorTypes = $grouped[$key] ?? [];
            $errorTotal = (int) \array_sum($errorTypes);

            $io->writeln(\sprintf('%s (%s): %d nodes processed, %d errors', $documentType, $workspace, $nodeCount, $errorTotal));

            foreach ($errorTypes as $exceptionClass => $count) {
                $io->writeln(\sprintf('  %s x%d', $exceptionClass, $count));
            }
            $io->writeln('');
        }

        $io->writeln(\sprintf('Total errors: %d', \count($this->errors)));
    }
}
