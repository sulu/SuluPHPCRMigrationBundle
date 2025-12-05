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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser;

use PHPCR\NodeInterface;

/**
 * Parses PHPCR CustomUrl nodes to array structure for Sulu 3.0 migration.
 */
class CustomUrlNodeParser implements NodeParserInterface
{
    public function __construct(
        private readonly PropertyNodeParser $propertyNodeParser,
    ) {
    }

    public function supports(NodeInterface $node, string $documentType): bool
    {
        if ('custom_url' !== $documentType) {
            return false;
        }

        foreach ($node->getMixinNodeTypes() as $mixinNodeType) {
            if ('sulu:custom_url' === $mixinNodeType->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{}|array{
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
     *     routes: array<int, array{uuid: string, path: string, history: bool, created: \DateTimeInterface, changed: \DateTimeInterface}>,
     *     created: \DateTimeInterface,
     *     changed: \DateTimeInterface,
     *     creator: int|null,
     *     changer: int|null,
     * }
     */
    public function parse(NodeInterface $node, string $documentType): array
    {
        if (!$this->supports($node, $documentType)) {
            return [];
        }

        $baseData = $this->propertyNodeParser->parse($node, $documentType);

        if ([] === $baseData) {
            return [];
        }

        $path = $node->getPath();
        $pathParts = \explode('/', $path);
        $webspace = $pathParts[2] ?? '';

        $routes = $this->parseRoutes($node);

        /** @var array{uuid?: string} $jcrData */
        $jcrData = $baseData['jcr'];
        /** @var array{title?: string, published?: bool, baseDomain?: string, domainParts?: array<string>, targetLocale?: string, canonical?: bool, redirect?: bool} $nullLocalization */
        $nullLocalization = $baseData['localizations']['null'] ?? [];
        /** @var array{created?: \DateTimeInterface, changed?: \DateTimeInterface, creator?: int, changer?: int, content?: string, noFollow?: bool, noIndex?: bool} $suluData */
        $suluData = $baseData['sulu'];

        return [
            'uuid' => $jcrData['uuid'] ?? '',
            'title' => $nullLocalization['title'] ?? '',
            'published' => $nullLocalization['published'] ?? false,
            'baseDomain' => $nullLocalization['baseDomain'] ?? '',
            'webspace' => $webspace,
            'domainParts' => $nullLocalization['domainParts'] ?? [],
            'targetDocument' => $suluData['content'] ?? null,
            'targetLocale' => $nullLocalization['targetLocale'] ?? 'en',
            'canonical' => $nullLocalization['canonical'] ?? false,
            'redirect' => $nullLocalization['redirect'] ?? false,
            'noFollow' => $suluData['noFollow'] ?? false,
            'noIndex' => $suluData['noIndex'] ?? false,
            'routes' => $routes,
            'created' => $suluData['created'] ?? new \DateTime(),
            'changed' => $suluData['changed'] ?? new \DateTime(),
            'creator' => $suluData['creator'] ?? null,
            'changer' => $suluData['changer'] ?? null,
        ];
    }

    /**
     * @return array<int, array{uuid: string, path: string, history: bool, created: \DateTimeInterface, changed: \DateTimeInterface}>
     */
    private function parseRoutes(NodeInterface $node): array
    {
        $routes = [];

        foreach ($node->getReferences('sulu:content') as $reference) {
            $routeNode = $reference->getParent();

            $isCustomUrlRoute = false;
            foreach ($routeNode->getMixinNodeTypes() as $mixinNodeType) {
                if ('sulu:custom_url_route' === $mixinNodeType->getName()) {
                    $isCustomUrlRoute = true;
                    break;
                }
            }

            if (!$isCustomUrlRoute) {
                continue;
            }

            $routePath = $routeNode->getPath();
            $routePathParts = \explode('/', $routePath);
            $pathSegments = \array_slice($routePathParts, 5);
            $extractedPath = \implode('/', $pathSegments);

            $uuidValue = $routeNode->getProperty('jcr:uuid')->getString();
            $historyValue = $routeNode->hasProperty('sulu:history')
                ? $routeNode->getProperty('sulu:history')->getBoolean()
                : false;

            $createdValue = $routeNode->hasProperty('sulu:created')
                ? $routeNode->getProperty('sulu:created')->getDate()
                : new \DateTime();
            $changedValue = $routeNode->hasProperty('sulu:changed')
                ? $routeNode->getProperty('sulu:changed')->getDate()
                : new \DateTime();

            $routes[] = [
                'uuid' => \is_array($uuidValue) ? $uuidValue[0] : $uuidValue,
                'path' => $extractedPath,
                'history' => \is_array($historyValue) ? (bool) $historyValue[0] : $historyValue,
                'created' => $createdValue instanceof \DateTimeInterface ? $createdValue : new \DateTime(),
                'changed' => $changedValue instanceof \DateTimeInterface ? $changedValue : new \DateTime(),
            ];
        }

        return $routes;
    }
}
