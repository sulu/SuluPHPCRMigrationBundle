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

namespace App\PhpcrFixture\Command;

use App\Entity\AppliedFixture;
use App\PhpcrFixture\PhpcrFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sulu:phpcr-migration:fixtures:apply',
    description: 'Apply PHPCR migration test fixtures to the Sulu 2.6 database.',
)]
final class ApplyFixturesCommand extends Command
{
    /**
     * @param iterable<PhpcrFixtureInterface> $fixtures
     */
    public function __construct(
        private readonly iterable $fixtures,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $appliedFixtures = $this->entityManager->getRepository(AppliedFixture::class)->findAll();
        $appliedNames = \array_map(static fn (AppliedFixture $f): string => $f->getName(), $appliedFixtures);

        $applied = 0;

        foreach ($this->fixtures as $fixture) {
            if (\in_array($fixture::class, $appliedNames, true)) {
                continue;
            }

            $io->writeln(\sprintf('> Applying <info>%s</info>', $fixture::class));
            try {
                $fixture->load();
            } catch (\Throwable $exception) {
                $io->error(\sprintf('Fixture "%s" failed: %s', $fixture::class, $exception->getMessage()));

                return Command::FAILURE;
            }

            $this->entityManager->persist(new AppliedFixture($fixture::class));
            $this->entityManager->flush();
            ++$applied;
        }

        $io->writeln(\sprintf('<info>%d applied</info>', $applied));

        if ($applied > 0) {
            $io->note('Run `composer export-fixture` from the bundle root to refresh sulu26_dump.sql.');
        }

        return Command::SUCCESS;
    }
}
