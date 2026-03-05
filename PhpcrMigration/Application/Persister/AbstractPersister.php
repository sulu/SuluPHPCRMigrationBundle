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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\InvalidDocumentException;
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
 *             audience_targeting_groups?: int[],
 *             segments?: array<string, string>,
 *         },
 *         _route?: array<string, mixed>,
 *         _history_urls?: string[],
 *         _url?: ?string,
 *         mainWebspace?: string,
 *         additionalWebspaces?: string[],
 *     }>
 * }
 * @phpstan-type DimensionContent array{
 *     id: int
 * }
 */
abstract class AbstractPersister implements PersisterInterface
{
    public const ROUTE_TABLE = 'ro_routes';

    public const URL = '_url';

    public const HISTORY_URLS = '_history_urls';

    public const ROUTE_RESOURCE_KEY = 'route_histories';

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
        $this->insertOrUpdateExcerptAudienceTargetGroups($document, $locale, $dimensionContent);
    }

    /**
     * Build the seoData JSON structure from localized data.
     *
     * @param array<string, mixed> $localizedData
     *
     * @return array<string, mixed>
     */
    protected function buildSeoData(array $localizedData): array
    {
        $seo = $localizedData['seo'] ?? [];
        if (!\is_array($seo) || [] === $seo) {
            return [];
        }

        return \array_filter([
            'title' => $seo['title'] ?? null,
            'description' => $seo['description'] ?? null,
            'keywords' => $seo['keywords'] ?? null,
            'canonicalUrl' => $seo['canonicalUrl'] ?? null,
        ], static fn ($value) => null !== $value);
    }

    /**
     * Build the excerptData JSON structure from localized data.
     *
     * @param array<string, mixed> $localizedData
     *
     * @return array<string, mixed>
     */
    protected function buildExcerptData(array $localizedData): array
    {
        $excerpt = $localizedData['excerpt'] ?? [];
        if (!\is_array($excerpt) || [] === $excerpt) {
            return [];
        }

        $imageId = $this->extractMediaId($excerpt['images'] ?? null);
        $iconId = $this->extractMediaId($excerpt['icon'] ?? null);

        // Validate media IDs exist
        if (null !== $imageId && !$this->entityRepository->exists('me_media', ['id' => $imageId])) {
            $imageId = null;
        }
        if (null !== $iconId && !$this->entityRepository->exists('me_media', ['id' => $iconId])) {
            $iconId = null;
        }

        $more = $excerpt['more'] ?? null;
        // Validate excerptMore length (max 63 chars)
        if (\is_string($more) && \strlen($more) >= 63) {
            $more = null;
        }

        return \array_filter([
            'title' => $excerpt['title'] ?? null,
            'description' => $excerpt['description'] ?? null,
            'more' => $more,
            'image' => null !== $imageId ? ['id' => $imageId] : null,
            'icon' => null !== $iconId ? ['id' => $iconId] : null,
        ], static fn ($value) => null !== $value);
    }

    /**
     * Extract media ID from various formats.
     */
    private function extractMediaId(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_array($value) && isset($value['ids'][0])) {
            return (int) $value['ids'][0];
        }

        return null;
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
                if (false === $this->entityRepository->exists('ca_categories', ['id' => $categoryId])) {
                    continue;
                }

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
     * @param DimensionContent $dimensionContent
     */
    protected function insertOrUpdateExcerptAudienceTargetGroups(array $document, ?string $locale, array $dimensionContent): void
    {
        if ($audienceTargetGroupIds = ($document['localizations'][$locale]['excerpt']['audience_targeting_groups'] ?? null)) {
            // remove all existing audience target groups
            $this->entityRepository->removeBy(
                $this->getDimensionContentExcerptAudienceTargetGroupsTableName(),
                [
                    $this->getDimensionContentExcerptAudienceTargetGroupsIdName() => $dimensionContent['id'],
                ],
            );

            foreach ($audienceTargetGroupIds as $audienceTargetGroupId) {
                $this->entityRepository->insertOrUpdate(
                    [
                        $this->getDimensionContentExcerptAudienceTargetGroupsIdName() => $dimensionContent['id'],
                        'target_group_id' => $audienceTargetGroupId,
                    ],
                    $this->getDimensionContentExcerptAudienceTargetGroupsTableName(),
                    [
                        $this->getDimensionContentExcerptAudienceTargetGroupsIdName() => 'integer',
                        'target_group_id' => 'integer',
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
        $ghostLocale = null;
        foreach ($localizations as $locale => $localization) {
            if ('null' !== $locale && null === $ghostLocale) {
                $ghostLocale = $locale;
            }

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
            $localizedData['ghostLocale'] = null;
            if (null === $locale) {
                $localizedData['availableLocales'] = $availableLocales;
                $localizedData['ghostLocale'] = $ghostLocale;
            }

            // Build JSON structures for seoData and excerptData (Sulu 3.0 format)
            $localizedData['_seoData'] = $this->buildSeoData($localizedData);
            $localizedData['_excerptData'] = $this->buildExcerptData($localizedData);

            try {
                $data = $this->mapDataViaMapping($localizedData, $this->getDimensionContentMapping());
                $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $created = $document['sulu']['created'] ?? null;
                $data['created'] = $created instanceof \DateTimeInterface ? $created->format('Y-m-d H:i:s') : $now;
                $changed = $document['sulu']['changed'] ?? null;
                $data['changed'] = $changed instanceof \DateTimeInterface ? $changed->format('Y-m-d H:i:s') : $now;

                $data = \array_merge($this->getDefaultData(), $data);
                $data = $this->mapDimensionContentData($document, $locale, $data, $isLive);

                $data = $this->validateData($data);

                // remove known keys that do not belong to the templateData
                $localizedData = $this->removeNonTemplateData($localizedData);

                /** @var mixed[] $templateData */
                $templateData = $data['templateData'] ?? [];
                $data['templateData'] = \array_merge($localizedData, $templateData);
                $data['templateData'] = $this->addBlockIds($data['templateData']);

                // SULU 3.0 MIGRATION FIX: Ensure ALL data is UTF-8 encoded (not just templateData)
                // This fixes title, seoTitle, excerptTitle, excerptDescription, and all other string fields
                $fixedData = $this->fixUtf8Encoding($data);
                \assert(\is_array($fixedData));
                $data = $fixedData;
            } catch (InvalidDocumentException $e) {
                echo \sprintf(
                    "Skipping dimension content for document with UUID '%s' and locale '%s': %s\n",
                    $document['jcr']['uuid'],
                    $locale ?? 'null',
                    $e->getMessage()
                );
                continue;
            }

            $data['version'] = 0;

            $entityIdMappingName = $this->getDimensionContentEntityIdMappingName();

            $this->entityRepository->insertOrUpdate(
                $data,
                $this->getDimensionContentTableName(),
                $this->getDimensionContentTableTypes(),
                [
                    $entityIdMappingName => $data[$entityIdMappingName],
                    'locale' => $locale,
                    'stage' => $data['stage'],
                ],
            );

            /**
             * @var DimensionContent $dimensionContent
             */
            $dimensionContent = $this->entityRepository->findOneBy($this->getDimensionContentTableName(), [
                $entityIdMappingName => $data[$entityIdMappingName],
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
            if (!\array_key_exists('state', $localizedData) || 1 === $localizedData['state']) {
                continue;
            }

            $parentId = $this->getParentId($document, $locale);
            $parentRouteId = $this->getParentRouteId($parentId, $locale);
            $webspace = $this->getWebspace($document, $locale);
            $resourceId = $document['jcr']['uuid'];
            $resourceKey = $this->getEntityResourceKey();

            $slug = $this->getSlug($document, $locale);
            if (null === $slug) {
                continue;
            }

            // main route
            $data = [
                'resource_key' => $resourceKey,
                'resource_id' => $resourceId,
                'locale' => $locale,
                'slug' => $this->getSlug($document, $locale),
                'webspace' => $webspace,
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
                    'webspace' => $webspace,
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
        // Provide a fallback for created/changed when the PHPCR node has no timestamps.
        $entityTypes = $this->getEntityTableTypes();
        $now = new \DateTime();
        if (isset($entityTypes['created']) && 'datetime' === $entityTypes['created']) {
            $data['created'] ??= $now;
        }
        if (isset($entityTypes['changed']) && 'datetime' === $entityTypes['changed']) {
            $data['changed'] ??= $now;
        }

        return $data;
    }

    /**
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function removeNonTemplateData(array $data): array
    {
        unset(
            $data['_url'],
            $data['_history_urls'],
            $data['_route'],
            $data['nodeType'],
            $data['_seoData'],
            $data['_excerptData'],
            $data['creator'],
            $data['changer'],
            $data['published'],
            $data['availableLocales'],
            $data['ghostLocale'],
            $data['routePathName'],
            $data['routePath']
        );

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
     * Generate a unique block ID using xxHash 32-bit algorithm.
     * Produces 8-character hexadecimal strings.
     *
     * Duplicates the logic from Sulu\Bundle\AdminBundle\BlockIdGenerator
     */
    private function generateBlockId(): string
    {
        // Use xxh32 hash with uniqid for short, unique IDs
        // uniqid with more_entropy=true provides microsecond precision
        // xxh32 produces 8-character hexadecimal strings
        return \hash('xxh32', \uniqid('', true));
    }

    /**
     * Check if an array is a block array (numerically indexed with 'type' keys).
     *
     * @param mixed[] $data
     */
    private function isBlockArray(array $data): bool
    {
        if ([] === $data || !\array_is_list($data)) {
            return false;
        }

        foreach ($data as $item) {
            if (\is_array($item) && isset($item['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively add block IDs to all blocks in the data structure.
     *
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    private function addBlockIds(array $data): array
    {
        if (!$this->isBlockArray($data)) {
            foreach ($data as $key => $value) {
                if (\is_array($value)) {
                    $data[$key] = $this->addBlockIds($value);
                }
            }

            return $data;
        }

        foreach ($data as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }

            /** @var array<array-key, mixed> $blockArray */
            $blockArray = $block;

            if (!\array_key_exists('_id', $blockArray)) {
                $blockArray['_id'] = $this->generateBlockId();
            }
            $data[$index] = $this->addBlockIds($blockArray);
        }

        return $data;
    }

    /**
     * @param Document $document
     */
    abstract public function supports(array $document): bool;

    abstract public static function getType(): string;

    abstract public function getEntityTableName(): string;

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

    abstract protected function getDimensionContentExcerptAudienceTargetGroupsTableName(): string;

    abstract protected function getDimensionContentExcerptAudienceTargetGroupsIdName(): string;

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
    protected function getWebspace(array $document, string $locale): ?string
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

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function validateData(array $data): array
    {
        $validators = [
            'author_id' => fn (mixed $value): bool => $this->entityRepository->exists('co_contacts', ['id' => $value]),
        ];

        foreach ($validators as $key => $isValid) {
            if (isset($data[$key]) && !$isValid($data[$key])) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /**
     * Fix UTF-8 encoding issues in data (recursively).
     * Converts malformed UTF-8 strings to valid UTF-8.
     */
    private function fixUtf8Encoding(mixed $data): mixed
    {
        if (\is_string($data)) {
            // Use iconv to aggressively strip invalid UTF-8 sequences
            // The //IGNORE flag will remove any characters that can't be converted
            $cleaned = @\iconv('UTF-8', 'UTF-8//IGNORE', $data);

            // If iconv failed, fallback to preg_replace to remove invalid UTF-8
            if (false === $cleaned) {
                // Remove invalid UTF-8 sequences using regex
                $cleanedPregReplace = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $data);
                // If still invalid, use mb_convert_encoding as last resort
                if (null === $cleanedPregReplace || !\mb_check_encoding($cleanedPregReplace, 'UTF-8')) {
                    $cleaned = \mb_convert_encoding($data, 'UTF-8', 'UTF-8');
                } else {
                    $cleaned = $cleanedPregReplace;
                }
            }

            return (string) ($cleaned ?: '');
        }

        if (\is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->fixUtf8Encoding($value);
            }

            return $data;
        }

        // For objects (like DateTime), numbers, booleans, null, etc. return as-is
        return $data;
    }
}
