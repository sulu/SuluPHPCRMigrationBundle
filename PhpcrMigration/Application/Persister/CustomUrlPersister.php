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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

/**
 * Persists CustomUrl data to Sulu 3.0 database structure.
 */
class CustomUrlPersister implements PersisterInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
    ) {
    }

    public function supports(array $document): bool
    {
        return isset($document['baseDomain']) && isset($document['domainParts']);
    }

    public static function getType(): string
    {
        return 'custom_url';
    }

    /**
     * @param array{
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
     * } $document
     */
    public function persist(array $document, bool $isLive): void
    {
        $customUrlData = [
            'uuid' => $document['uuid'],
            'title' => $document['title'],
            'published' => $document['published'],
            'base_domain' => $document['baseDomain'],
            'webspace' => $document['webspace'],
            'domain_parts' => $document['domainParts'],
            'target_document' => $document['targetDocument'],
            'target_locale' => $document['targetLocale'],
            'canonical' => $document['canonical'],
            'redirect' => $document['redirect'],
            'no_follow' => $document['noFollow'],
            'no_index' => $document['noIndex'],
            'created' => $document['created'],
            'changed' => $document['changed'],
            'idUsersCreator' => $document['creator'],
            'idUsersChanger' => $document['changer'],
        ];

        $this->entityRepository->insertOrUpdate(
            $customUrlData,
            'cu_custom_url',
            [
                'uuid' => 'string',
                'title' => 'string',
                'published' => 'boolean',
                'base_domain' => 'string',
                'webspace' => 'string',
                'domain_parts' => 'json',
                'target_document' => 'string',
                'target_locale' => 'string',
                'canonical' => 'boolean',
                'redirect' => 'boolean',
                'no_follow' => 'boolean',
                'no_index' => 'boolean',
                'created' => 'datetime',
                'changed' => 'datetime',
                'idUsersCreator' => 'integer',
                'idUsersChanger' => 'integer',
            ],
            ['uuid' => $document['uuid']],
        );

        $this->entityRepository->removeBy(
            'cu_custom_url_route',
            ['customUrl' => $document['uuid']],
        );

        foreach ($document['routes'] as $route) {
            $routeData = [
                'uuid' => $route['uuid'],
                'customUrl' => $document['uuid'],
                'path' => $route['path'],
                'history' => $route['history'],
                'created' => $route['created'],
                'changed' => $route['changed'],
                'target_route_uuid' => $route['targetRouteUuid'],
            ];

            $this->entityRepository->insertOrUpdate(
                $routeData,
                'cu_custom_url_route',
                [
                    'uuid' => 'string',
                    'customUrl' => 'string',
                    'path' => 'string',
                    'history' => 'boolean',
                    'created' => 'datetime',
                    'changed' => 'datetime',
                    'target_route_uuid' => 'string',
                ],
                ['uuid' => $route['uuid']],
            );
        }
    }
}
