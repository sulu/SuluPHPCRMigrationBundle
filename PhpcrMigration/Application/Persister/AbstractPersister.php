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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\RoutePathNameNotFoundException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\UnsupportedDocumentTypeException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * @phpstan-type Document array{
 *     jcr: array{uuid: string, mixinTypes: string[]},
 *     sulu: array<string, mixed>,
 *     localizations: array<string, array{
 *         routePath?: string,
 *         routePathName?: string,
 *         template: string,
 *         state: int,
 *         url?: string,
 *         navContexts?: string[],
 *         excerpt?: array{
 *             categories?: int[],
 *             tags?: int[],
 *         },
 *         _route?: array<string, mixed>,
 *         _history_urls?: string[],
 *         _url?: ?string,
 *     }>
 * }
 * @phpstan-type DimensionContent array{
 *     id: int
 * }
 */
abstract class AbstractPersister implements PersisterInterface
{
    // TODO this needs to be ro_routes after the legacy RouteBundle is removed
    public const ROUTE_TABLE = 'ro_next_routes';

    public const URL = '_url';

    public const HISTORY_URLS = '_history_urls';

    public const ROUTE_RESOURCE_KEY = 'route_history';

    public function __construct(
        protected PropertyAccessorInterface $propertyAccessor,
        protected EntityRepositoryInterface $entityRepository,
    ) {
    }

    /**
     * @param Document $document
     */
    public function persist(array $document, bool $isLive): void
    {
        if (false === $this->supports($document)) {
            throw new UnsupportedDocumentTypeException($document['jcr']['mixinTypes']);
        }

        foreach ($document['localizations'] as $locale => $localizedData) {
            if (
                [] !== $localizedData
                && isset($localizedData['routePath'])
                && !isset($localizedData['routePathName'])
            ) {
                throw new RoutePathNameNotFoundException($document['jcr']['uuid'], $locale);
            }
        }

        if ($this->isRoutable()) {
            $routes = $this->createOrUpdateRoutes($document);
            foreach ($routes as $locale => $route) {
                // We have to add the routes to the document, because the
                // `createOrUpdateDimensionContent` method needs them to
                // set the relation between the DimensionContent and the Route.
                $document['localizations'][$locale]['_route'] = $route;
            }
        }

        $this->entityRepository->beginTransaction();
        $this->createOrUpdateEntity($document);
        $this->createOrUpdateDimensionContent($document, $isLive);
        $this->entityRepository->commit();
    }

    /**
     * @param mixed[] $data
     * @param array<string, string> $mapping
     *
     * @return mixed[]
     */
    protected function mapDataViaMapping(array &$data, array $mapping): array
    {
        $mappedData = [];
        foreach ($mapping as $target => $source) {
            if (null === $this->propertyAccessor->getValue($data, $source)) {
                continue;
            }
            $this->propertyAccessor->setValue(
                $mappedData,
                $target,
                $this->propertyAccessor->getValue($data, $source),
            );
        }

        return $mappedData;
    }

    /**
     * @param Document $document
     * @param DimensionContent $dimensionContent
     */
    protected function insertDataRelationsToDimensionContent(array $document, ?string $locale, array $dimensionContent): void
    {
        $this->insertOrUpdateExcerptCategories($document, $locale, $dimensionContent);
        $this->insertOrUpdateExcerptTags($document, $locale, $dimensionContent);
    }

    /**
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function mapExcerptImages(array $data): array
    {
        if ($data['excerptImageId'] ?? null) {
            $data['excerptImageId'] = $data['excerptImageId']['ids'][0] ?? null;
        }

        return $data;
    }

    /**
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function mapExcerptIcons(array $data): array
    {
        if ($data['excerptIconId'] ?? null) {
            $data['excerptIconId'] = $data['excerptIconId']['ids'][0] ?? null;
        }

        return $data;
    }

    /**
     * @param Document $document
     * @param DimensionContent $dimensionContent
     */
    protected function insertOrUpdateExcerptCategories(array $document, ?string $locale, array $dimensionContent): void
    {
        if ($categoryIds = ($document['localizations'][$locale]['excerpt']['categories'] ?? null)) {
            // remove all existing categories
            $this->entityRepository->removeBy(
                $this->getDimensionContentExcerptCategoriesTableName(),
                [
                    $this->getDimensionContentExcerptCategoriesIdName() => $dimensionContent['id'],
                ],
            );

            foreach ($categoryIds as $categoryId) {
                $this->entityRepository->insertOrUpdate(
                    [
                        $this->getDimensionContentExcerptCategoriesIdName() => $dimensionContent['id'],
                        'category_id' => $categoryId,
                    ],
                    $this->getDimensionContentExcerptCategoriesTableName(),
                    [
                        $this->getDimensionContentExcerptCategoriesIdName() => 'integer',
                        'category_id' => 'integer',
                    ],
                );
            }
        }
    }

    /**
     * @param Document $document
     * @param DimensionContent $dimensionContent
     */
    protected function insertOrUpdateExcerptTags(array $document, ?string $locale, array $dimensionContent): void
    {
        if ($tagIds = ($document['localizations'][$locale]['excerpt']['tags'] ?? null)) {
            // remove all existing tags
            $this->entityRepository->removeBy(
                $this->getDimensionContentExcerptTagsTableName(),
                [
                    $this->getDimensionContentExcerptTagsIdName() => $dimensionContent['id'],
                ],
            );

            foreach ($tagIds as $tagId) {
                $this->entityRepository->insertOrUpdate(
                    [
                        $this->getDimensionContentExcerptTagsIdName() => $dimensionContent['id'],
                        'tag_id' => $tagId,
                    ],
                    $this->getDimensionContentExcerptTagsTableName(),
                    [
                        $this->getDimensionContentExcerptTagsIdName() => 'integer',
                        'tag_id' => 'integer',
                    ],
                );
            }
        }
    }

    /**
     * @param Document $document
     */
    protected function createOrUpdateEntity(array $document): void
    {
        $data = $this->mapDataViaMapping($document, $this->getEntityMapping());
        $data = $this->mapEntityData($document, $data);

        // if parentId key exists, we assume that this is a nested document
        if (\array_key_exists('parentId', $document['sulu'])) {
            if (null === $document['sulu']['parentId']) {
                $this->entityRepository->createOrUpdateRootNode(
                    $data,
                    $this->getEntityTableName(),
                    $this->getEntityTableTypes(),
                    [
                        'uuid' => $data['uuid'],
                    ],
                );
            } else {
                $this->entityRepository->addOrUpdateChildNode(
                    $data,
                    $this->getEntityTableName(),
                    $this->getEntityTableTypes(),
                    $document['sulu']['parentId'],
                    [
                        'uuid' => $data['uuid'],
                    ],
                );
            }

            return;
        }

        $this->entityRepository->insertOrUpdate(
            $data,
            $this->getEntityTableName(),
            $this->getEntityTableTypes(),
            [
                'uuid' => $data['uuid'],
            ],
        );
    }

    /**
     * @param Document $document
     */
    protected function createOrUpdateDimensionContent(array $document, bool $isLive): void
    {
        /** @var array<string, mixed[]> $localizations */
        $localizations = $document['localizations'];

        $availableLocales = [];
        foreach ($localizations as $locale => $localization) {
            // add only published locales to availableLocales
            if (\array_key_exists('state', $localization) && 2 === $localization['state']) {
                $availableLocales[] = $locale;
            }
        }
        /**
         * @var array{
         *     availableLocales?: string[],
         *     templateData?: mixed[],
         * } $localizedData
         * @var string $locale
         */
        foreach ($localizations as $locale => $localizedData) {
            $locale = 'null' === $locale ? null : $locale;

            $localizedData['availableLocales'] = null;
            if (null === $locale) {
                $localizedData['availableLocales'] = $availableLocales;
            }

            $data = $this->mapDataViaMapping($localizedData, $this->getDimensionContentMapping());
            $data = \array_merge($this->getDefaultData(), $data);
            $data = $this->mapExcerptImages($data);
            $data = $this->mapExcerptIcons($data);
            $data = $this->mapDimensionContentData($document, $locale, $data, $isLive);

            // remove known keys that do not belong to the templateData
            $localizedData = $this->removeNonTemplateData($localizedData);

            /** @var mixed[] $templateData */
            $templateData = $data['templateData'] ?? [];
            $data['templateData'] = \array_merge($localizedData, $templateData);

            $this->entityRepository->insertOrUpdate(
                $data,
                $this->getDimensionContentTableName(),
                $this->getDimensionContentTableTypes(),
                [
                    $this->getDimensionContentEntityIdMappingName() => $data[$this->getDimensionContentEntityIdMappingName()],
                    'locale' => $locale,
                    'stage' => $data['stage'],
                ],
            );

            /**
             * @var DimensionContent $dimensionContent
             */
            $dimensionContent = $this->entityRepository->findOneBy($this->getDimensionContentTableName(), [
                $this->getDimensionContentEntityIdMappingName() => $data[$this->getDimensionContentEntityIdMappingName()],
                'locale' => $locale,
                'stage' => $data['stage'],
            ]);

            $this->insertDataRelationsToDimensionContent($document, $locale, $dimensionContent);
        }
    }

    /**
     * @param Document $document
     *
     * @return array<string, array<string, mixed>>
     */
    protected function createOrUpdateRoutes(array $document): array
    {
        $routes = [];
        $localizations = $document['localizations'];
        foreach ($localizations as $locale => $localizedData) {
            // skip unlocalized data
            if ('null' === $locale) {
                continue;
            }
            // skip non-published entries
            if (1 === $localizedData['state']) {
                continue;
            }

            $parentId = $this->getParentId($document, $locale);
            $parentRouteId = $this->getParentRouteId($parentId, $locale);
            $site = $this->getSite($document, $locale);
            $resourceId = $document['jcr']['uuid'];
            $resourceKey = $this->getEntityResourceKey();

            // main route
            $data = [
                'resource_key' => $resourceKey,
                'resource_id' => $resourceId,
                'locale' => $locale,
                'slug' => $this->getSlug($document, $locale),
                'site' => $site,
                'parent_id' => $parentRouteId,
            ];

            $this->entityRepository->insertOrUpdate(
                $data,
                self::ROUTE_TABLE,
                [
                    'resource_id' => 'string',
                    'resource_key' => 'string',
                    'slug' => 'string',
                    'locale' => 'string',
                    'parent_id' => 'integer',
                ],
                [
                    'resource_id' => $resourceId,
                    'resource_key' => $resourceKey,
                    'locale' => $locale,
                ],
            );

            $route = $this->entityRepository->findOneBy(self::ROUTE_TABLE, [
                'resource_id' => $resourceId,
                'resource_key' => $resourceKey,
                'locale' => $locale,
            ]);

            if (null !== $route) {
                $routes[$locale] = $route;
            }

            // history routes
            $historyUrls = $localizedData[AbstractPersister::HISTORY_URLS] ?? null;
            if (null === $historyUrls) {
                continue;
            }

            $historyResourceId = $resourceKey . '::' . $resourceId;
            foreach ($historyUrls as $url) {
                $data = [
                    'resource_key' => AbstractPersister::ROUTE_RESOURCE_KEY,
                    'resource_id' => $historyResourceId,
                    'locale' => $locale,
                    'slug' => $url,
                    'site' => $site,
                    'parent_id' => null, // history urls are disconnected from the parent to prevent unexpected changes
                ];

                $this->entityRepository->insertOrUpdate(
                    $data,
                    self::ROUTE_TABLE,
                    [
                        'resource_id' => 'string',
                        'resource_key' => 'string',
                        'slug' => 'string',
                        'locale' => 'string',
                        'parent_id' => 'integer',
                    ],
                    [
                        'resource_id' => $historyResourceId,
                        'resource_key' => AbstractPersister::ROUTE_RESOURCE_KEY,
                        'slug' => $url,
                        'locale' => $locale,
                    ],
                );
            }
        }

        return $routes;
    }

    protected function getParentRouteId(?string $parentId, ?string $locale): ?int
    {
        if (null === $parentId) {
            return null;
        }

        /** @var array{id?: int} $parentRoute */
        $parentRoute = $this->entityRepository->findOneBy(self::ROUTE_TABLE, [
            'resource_key' => $this->getEntityResourceKey(),
            'resource_id' => $parentId,
            'locale' => $locale,
        ]);

        return $parentRoute['id'] ?? null;
    }

    /**
     * @param Document $document
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function mapDimensionContentData(array $document, ?string $locale, array $data, bool $isLive): array
    {
        $data['templateData'] = [];

        return $data;
    }

    /**
     * @param Document $document
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function mapEntityData(array $document, array $data): array
    {
        return $data;
    }

    /**
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function removeNonTemplateData(array $data): array
    {
        unset($data['_url'], $data['_history_urls'], $data['_route'], $data['nodeType']);
        foreach ($data as $key => $value) {
            // remove block-length property
            if (\is_array($value) && \is_int($data[$key . '-length'] ?? null)) {
                $data[$key . '-length'] = null;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDefaultData(): array
    {
        return [];
    }

    /**
     * @param Document $document
     */
    abstract public function supports(array $document): bool;

    abstract public static function getType(): string;

    abstract protected function getEntityTableName(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function getEntityTableTypes(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function getEntityMapping(): array;

    abstract protected function getDimensionContentTableName(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function getDimensionContentTableTypes(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function getDimensionContentMapping(): array;

    abstract protected function getDimensionContentEntityIdMappingName(): string;

    abstract protected function getEntityResourceKey(): string;

    abstract protected function getDimensionContentExcerptCategoriesTableName(): string;

    abstract protected function getDimensionContentExcerptCategoriesIdName(): string;

    abstract protected function getDimensionContentExcerptTagsTableName(): string;

    abstract protected function getDimensionContentExcerptTagsIdName(): string;

    /**
     * @param Document $document
     */
    protected function getSlug(array $document, string $locale): ?string
    {
        return null;
    }

    /**
     * @param Document $document
     */
    protected function getSite(array $document, string $locale): ?string
    {
        return null;
    }

    /**
     * @param Document $document
     */
    protected function getParentId(array $document, string $locale): ?string
    {
        return null;
    }

    abstract protected function isRoutable(): bool;
}
