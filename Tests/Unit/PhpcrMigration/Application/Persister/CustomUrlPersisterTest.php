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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Persister;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\CustomUrlPersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

#[CoversClass(CustomUrlPersister::class)]
final class CustomUrlPersisterTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @return iterable<string, array{int|null, int|null, int|null, int|null}>
     */
    public static function provideUsers(): iterable
    {
        yield 'creator deleted' => [7, 8, null, 8];
        yield 'changer deleted' => [8, 7, 8, null];
        yield 'both deleted' => [7, 7, null, null];
        yield 'both existing' => [8, 8, 8, 8];
        yield 'both unset' => [null, null, null, null];
    }

    #[DataProvider('provideUsers')]
    public function testPersistNullsCreatorAndChangerOfDeletedUsers(
        ?int $creator,
        ?int $changer,
        ?int $expectedCreator,
        ?int $expectedChanger,
    ): void {
        $repository = $this->prophesize(EntityRepositoryInterface::class);
        $repository->exists('se_users', ['id' => 7])->willReturn(false);
        $repository->exists('se_users', ['id' => 8])->willReturn(true);
        $repository->removeBy(Argument::cetera())->willReturn(0);

        $persister = new CustomUrlPersister($repository->reveal());
        $persister->persist($this->createDocument($creator, $changer), true);

        $repository->insertOrUpdate(
            Argument::that(
                static fn (array $data): bool => $data['idUsersCreator'] === $expectedCreator
                    && $data['idUsersChanger'] === $expectedChanger,
            ),
            'cu_custom_url',
            Argument::type('array'),
            ['uuid' => 'custom-url-uuid'],
        )->shouldHaveBeenCalledOnce();
    }

    /**
     * @return array{
     *     uuid: string,
     *     title: string,
     *     published: bool,
     *     baseDomain: string,
     *     webspace: string,
     *     domainParts: array<string>,
     *     targetDocument: string|null,
     *     targetLocale: string,
     *     canonical: bool,
     *     redirect: bool,
     *     noFollow: bool,
     *     noIndex: bool,
     *     routes: array<int, array{uuid: string, path: string, history: bool, targetRouteUuid: string|null, created: \DateTimeInterface, changed: \DateTimeInterface}>,
     *     created: \DateTimeInterface,
     *     changed: \DateTimeInterface,
     *     creator: int|null,
     *     changer: int|null,
     * }
     */
    private function createDocument(?int $creator, ?int $changer): array
    {
        $now = new \DateTimeImmutable();

        return [
            'uuid' => 'custom-url-uuid',
            'title' => 'Custom URL',
            'published' => true,
            'baseDomain' => '*.example.org',
            'webspace' => 'example',
            'domainParts' => ['prefix' => 'hello'],
            'targetDocument' => null,
            'targetLocale' => 'en',
            'canonical' => false,
            'redirect' => false,
            'noFollow' => false,
            'noIndex' => false,
            'routes' => [],
            'created' => $now,
            'changed' => $now,
            'creator' => $creator,
            'changer' => $changer,
        ];
    }
}
