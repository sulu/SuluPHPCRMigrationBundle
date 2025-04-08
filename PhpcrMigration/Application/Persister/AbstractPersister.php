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
 *         }
 *     }>
 * }
 * @phpstan-type DimensionContent array{
 *     id: int
 * }
 */
abstract class AbstractPersister implements PersisterInterface
{
    // TODO revert this constant to `ro_routes` after the legacy SuluRoutingBundle is removed
    public const ROUTE_TABLE = 'ro_next_routes';

    public const LEGACY_ROUTE_TABLE = 'ro_routes';

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
                $document['localizations'][$locale]['_route'] = $route;
            }

            $historyRoutes = $this->createOrUpdateHistoryRoutes($document);
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
            $dimensionContent = $this->entityRepository->findBy($this->getDimensionContentTableName(), [
                $this->getDimensionContentEntityIdMappingName() => $data[$this->getDimensionContentEntityIdMappingName()],
                'locale' => $locale,
                'stage' => $data['stage'],
            ]);

            $this->insertDataRelationsToDimensionContent($document, $locale, $dimensionContent);
        }
    }

    /**
     * @param Document $document
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

            // TODO history (read from legacy route table and write to new route table the history entries)
            //            $existingRoute = $this->entityRepository->findBy(self::ROUTE_TABLE, [
            //                'resource_key' => $this->getEntityResourceKey(),
            //                'resource_id' => $document['jcr']['uuid'],
            //                'locale' => $locale,
            //            ]) ?? [];
            //                'history' => $existingRoute['history'] ?? 0,

            $parentId = $this->getParentId($document, $locale);
            $data = [
                'resource_key' => $this->getEntityResourceKey(),
                'resource_id' => $document['jcr']['uuid'],
                'locale' => $locale,
                'slug' => $this->getSlug($document, $locale),
                'site' => $this->getSite($document, $locale),
                'parent_id' => $this->getParentRouteId($parentId, $locale),
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
                    'resource_id' => $document['jcr']['uuid'],
                    'resource_key' => $this->getEntityResourceKey(),
                    'locale' => $locale,
                ],
            );

            $route = $this->entityRepository->findBy(self::ROUTE_TABLE, [
                'resource_id' => $document['jcr']['uuid'],
                'resource_key' => $this->getEntityResourceKey(),
                'locale' => $locale,
            ]);

            $routes[$locale] = $route;
        }

        return $routes;
    }

    protected function getParentRouteId(?string $parentId, ?string $locale): ?int
    {
        if (null === $parentId) {
            return null;
        }

        $parentRoute = $this->entityRepository->findBy(self::ROUTE_TABLE, [
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

    protected function createOrUpdateHistoryRoutes(array $document): void
    {
        return;
    }
}
