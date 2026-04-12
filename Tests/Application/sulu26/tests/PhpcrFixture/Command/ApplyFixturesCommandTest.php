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

namespace App\Tests\PhpcrFixture\Command;

use App\Entity\AppliedFixture;
use App\PhpcrFixture\Command\ApplyFixturesCommand;
use App\PhpcrFixture\PhpcrFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplyFixturesCommandTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<EntityManagerInterface>
     */
    private ObjectProphecy $entityManager;

    /**
     * @var ObjectProphecy<EntityRepository<AppliedFixture>>
     */
    private ObjectProphecy $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
        $this->repository = $this->prophesize(EntityRepository::class);
        $this->entityManager->getRepository(AppliedFixture::class)->willReturn($this->repository->reveal());
    }

    public function testAppliesPendingFixtures(): void
    {
        $fixture = new TestFixture();

        $this->repository->findAll()->willReturn([]);
        $this->entityManager->persist(Argument::type(AppliedFixture::class))->shouldBeCalledOnce();
        $this->entityManager->flush()->shouldBeCalled();

        $tester = $this->tester([$fixture]);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('1 applied', $tester->getDisplay());
    }

    public function testSkipsAlreadyAppliedFixtures(): void
    {
        $fixture = new TestFixture();

        $this->repository->findAll()->willReturn([new AppliedFixture(TestFixture::class)]);
        $this->entityManager->persist(Argument::any())->shouldNotBeCalled();

        $tester = $this->tester([$fixture]);
        $tester->execute([]);

        self::assertStringContainsString('0 applied', $tester->getDisplay());
    }

    /**
     * @param iterable<PhpcrFixtureInterface> $fixtures
     */
    private function tester(iterable $fixtures): CommandTester
    {
        $command = new ApplyFixturesCommand(
            $fixtures,
            $this->entityManager->reveal(),
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('sulu:phpcr-migration:fixtures:apply'));
    }
}

/**
 * @internal
 */
final class TestFixture implements PhpcrFixtureInterface
{
    public function load(): void
    {
    }
}
