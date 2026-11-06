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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\UserInterface\Command;

use Doctrine\DBAL\Connection;
use PHPCR\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\NodeParserInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PersisterPool;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\CollationChecker;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\DryRunCollector;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Session\SessionManager;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MigratePhpcrCommand::class)]
final class MigratePhpcrCommandCollationTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<SessionManager>
     */
    private ObjectProphecy $sessionManager;

    /**
     * @var ObjectProphecy<CollationChecker>
     */
    private ObjectProphecy $collationChecker;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->sessionManager = $this->prophesize(SessionManager::class);
        $this->sessionManager->getDefaultSession()->willReturn($this->prophesize(SessionInterface::class)->reveal());
        $this->sessionManager->getLiveSession()->willReturn($this->prophesize(SessionInterface::class)->reveal());

        $this->collationChecker = $this->prophesize(CollationChecker::class);

        $this->tester = new CommandTester(new MigratePhpcrCommand(
            $this->sessionManager->reveal(),
            $this->prophesize(NodeParserInterface::class)->reveal(),
            new PersisterPool([]),
            [],
            $this->prophesize(Connection::class)->reveal(),
            $this->prophesize(EntityRepositoryInterface::class)->reveal(),
            new DryRunCollector(),
            \sys_get_temp_dir(),
            $this->collationChecker->reveal(),
        ));
    }

    public function testMigrationRunsWithoutWarningWhenCollationsMatch(): void
    {
        $this->collationChecker->findMixedCollations()->willReturn([]);

        $this->tester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertStringNotContainsString('mismatch of collations', $this->tester->getDisplay());
    }

    public function testMigrationStopsWhenTheUserDeclinesAfterTheWarning(): void
    {
        $this->collationChecker->findMixedCollations()->willReturn([
            'utf8mb4_unicode_ci' => ['pa_pages', 'ro_routes', 'sn_snippets'],
            'utf8mb4_0900_ai_ci' => ['ro_routes'],
        ]);
        $this->tester->setInputs(['n']);

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertStringContainsString('mismatch of collations', $display);
        $this->assertStringContainsString('utf8mb4_unicode_ci: 3 tables', $display);
        $this->assertStringContainsString('utf8mb4_0900_ai_ci: ro_routes', $display);
        $this->sessionManager->getDefaultSession()->shouldNotHaveBeenCalled();
    }

    public function testMigrationContinuesWhenTheUserConfirms(): void
    {
        $this->collationChecker->findMixedCollations()->willReturn([
            'utf8mb4_unicode_ci' => ['pa_pages'],
            'utf8mb4_0900_ai_ci' => ['ro_routes'],
        ]);
        $this->tester->setInputs(['']);

        $this->tester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->sessionManager->getDefaultSession()->shouldHaveBeenCalled();
    }

    public function testMigrationContinuesInNonInteractiveMode(): void
    {
        $this->collationChecker->findMixedCollations()->willReturn([
            'utf8mb4_unicode_ci' => ['pa_pages'],
            'utf8mb4_0900_ai_ci' => ['ro_routes'],
        ]);

        $this->tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertStringContainsString('mismatch of collations', $this->tester->getDisplay());
        $this->sessionManager->getDefaultSession()->shouldHaveBeenCalled();
    }
}
