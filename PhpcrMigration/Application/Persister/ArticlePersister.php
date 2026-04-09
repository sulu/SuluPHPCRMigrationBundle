<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\TitleTooLongException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * @phpstan-import-type Document from AbstractPersister
 * @phpstan-import-type DimensionContent from AbstractPersister
 */
class ArticlePersister extends AbstractPersister
{
    /**
     * @param array<string, string> $defaultMainWebspaceMap
     * @param array<string, list<string>> $defaultAdditionalWebspacesMap
     */
    public function __construct(
        PropertyAccessorInterface $propertyAccessor,
        EntityRepositoryInterface $entityRepository,
        private readonly array $defaultMainWebspaceMap = [],
        private readonly array $defaultAdditionalWebspacesMap = [],
    ) {
        parent::__construct($propertyAccessor, $entityRepository);
    }

    protected function removeNonTemplateData(array $data): array
    {
        $data = parent::removeNonTemplateData($data);

        $data['shadow-on'] = null;
        $data['shadow-base'] = null;
        $data['seo'] = null;
        $data['excerpt'] = null;
        $data['stage'] = null;
        $data['suluPages'] = null;
        $data['author'] = null;
        $data['authored'] = null;
        $data['template'] = null;
        $data['state'] = null;
        $data['availableLocales'] = null;
        $data['routePathName'] = null;
        $data['mainWebspace'] = null;
        $data['additionalWebspaces'] = null;

        return \array_filter($data, static fn ($entry) => null !== $entry);
    }

    protected function mapDimensionContentData(array $document, ?string $locale, array $data, bool $isLive): array
    {
        $data = parent::mapDimensionContentData($document, $locale, $data, $isLive);
        $data = $this->mapShadowLocaleData($document, $locale, $data);

        $data[$this->getDimensionContentEntityIdMappingName()] = $document['jcr']['uuid'];
        $data['locale'] = $locale;
        $data['stage'] = $isLive ? 'live' : 'draft';
        $data['workflowPlace'] = 2 === ($data['workflowPlace'] ?? null) ? 'published' : 'draft';

        /** @var array<string, mixed> $templateData */
        $templateData = $data['templateData'] ?? [];

        if (isset($data['title']) && \is_scalar($data['title'])) {
            $title = (string) $data['title'];

            if (\strlen($title) > TitleTooLongException::MAX_LENGTH) {
                throw new TitleTooLongException($title, $document['jcr']['uuid'], $locale);
            }

            $data['title'] = $title;
            $templateData['title'] = $title;
        }

        if (null !== $locale && isset($document['localizations'][$locale])) {
            $url = $this->resolveRoutePath($document['localizations'][$locale]);

            if (null !== $url) {
                // content bundle is only compatible with "url"
                $templateData['url'] = $url;
            }
        }

        $data['templateData'] = $templateData;

        // Transform segments map to single segment value
        // For articles, take the first segment value from the map
        if (isset($data['excerptSegment']) && \is_array($data['excerptSegment'])) {
            $segments = $data['excerptSegment'];
            $data['excerptSegment'] = [] === $segments ? null : \reset($segments);
        }

        // customizeWebspaceSettings is true if mainWebspace is explicitly set in PHPCR
        $data['customizeWebspaceSettings'] = isset($document['localizations'][$locale]['mainWebspace']);

        // In Sulu 2.6, default webspaces were applied at runtime. In 3.0 they must be stored.
        // Apply configured defaults when PHPCR has no explicit webspace settings,
        // or when customizeWebspaceSettings is true but mainWebspace ended up null (invalid in Sulu 3).
        if ([] !== $this->defaultMainWebspaceMap && null !== $locale && !isset($data['mainWebspace'])) {
            $defaultMainWebspace = $this->defaultMainWebspaceMap[$locale]
                ?? $this->defaultMainWebspaceMap['default']
                ?? null;
            if (null !== $defaultMainWebspace) {
                $data['mainWebspace'] = $defaultMainWebspace;
            }
        }

        return $data;
    }

    public function supports(array $document): bool
    {
        return \in_array('sulu:article', $document['jcr']['mixinTypes']);
    }

    public static function getType(): string
    {
        return 'article';
    }

    public function getEntityTableName(): string
    {
        return 'ar_articles';
    }

    protected function getEntityTableTypes(): array
    {
        return [
            'uuid' => 'string',
            'created' => 'datetime',
            'changed' => 'datetime',
        ];
    }

    protected function getEntityMapping(): array
    {
        return [
            '[uuid]' => '[jcr][uuid]',
            '[created]' => '[sulu][created]',
            '[changed]' => '[sulu][changed]',
        ];
    }

    protected function getDimensionContentTableName(): string
    {
        return 'ar_article_dimension_contents';
    }

    protected function getDimensionContentTableTypes(): array
    {
        return [
            'author_id' => 'integer',
            'authored' => 'datetime',
            'route_id' => 'integer',
            'title' => 'string',
            'locale' => 'string',
            'ghostLocale' => 'string',
            'availableLocales' => 'json',
            'templateKey' => 'string',
            'stage' => 'string',
            'workflowPlace' => 'string',
            'workflowPublished' => 'datetime',
            'seoData' => 'json',
            'seoNoIndex' => 'boolean',
            'seoNoFollow' => 'boolean',
            'seoHideInSitemap' => 'boolean',
            'excerptData' => 'json',
            'excerptSegment' => 'string',
            'templateData' => 'json',
            'mainWebspace' => 'string',
            'customizeWebspaceSettings' => 'boolean',
            'shadowLocale' => 'string',
            'shadowLocales' => 'json',
        ];
    }

    protected function getDimensionContentMapping(): array
    {
        return [
            '[author_id]' => '[author]',
            '[authored]' => '[authored]',
            '[route_id]' => '[_route][id]',
            '[title]' => '[title]',
            '[ghostLocale]' => '[ghostLocale]',
            '[availableLocales]' => '[availableLocales]',
            '[templateKey]' => '[template]',
            '[workflowPlace]' => '[state]',
            '[workflowPublished]' => '[published]',
            // Sulu 3.0: SEO data consolidated into JSON column
            '[seoData]' => '[_seoData]',
            '[seoNoIndex]' => '[seo][noIndex]',
            '[seoNoFollow]' => '[seo][noFollow]',
            '[seoHideInSitemap]' => '[seo][hideInSitemap]',
            // Sulu 3.0: Excerpt data consolidated into JSON column
            '[excerptData]' => '[_excerptData]',
            '[excerptSegment]' => '[excerpt][segments]',
            // Sulu 3.0: Webspace settings
            '[mainWebspace]' => '[mainWebspace]',
        ];
    }

    protected function getDimensionContentEntityIdMappingName(): string
    {
        return 'articleUuid';
    }

    protected function getEntityResourceKey(): string
    {
        return 'articles';
    }

    protected function getDimensionContentExcerptCategoriesTableName(): string
    {
        return 'ar_article_dimension_content_excerpt_categories';
    }

    protected function getDimensionContentExcerptCategoriesIdName(): string
    {
        return 'article_dimension_content_id';
    }

    protected function getDimensionContentExcerptTagsTableName(): string
    {
        return 'ar_article_dimension_content_excerpt_tags';
    }

    protected function getDimensionContentExcerptTagsIdName(): string
    {
        return 'article_dimension_content_id';
    }

    protected function getDimensionContentExcerptAudienceTargetGroupsTableName(): string
    {
        return 'ar_article_dimension_content_excerpt_audience_target_groups';
    }

    protected function getDimensionContentExcerptAudienceTargetGroupsIdName(): string
    {
        return 'article_dimension_content_id';
    }

    /**
     * Resolves the route path from localized PHPCR data.
     * Uses routePathName to find the actual property, falls back to routePath.
     *
     * @param array<string, mixed> $localeData
     */
    private function resolveRoutePath(array $localeData): ?string
    {
        if (isset($localeData['routePathName']) && \is_string($localeData['routePathName'])) {
            $routePathName = $localeData['routePathName'];
            // Handle i18n prefix (e.g., 'i18n:en-routePath' -> 'routePath')
            $routePathName = \str_starts_with($routePathName, 'i18n:')
                ? \explode('-', $routePathName, 2)[1]
                : $routePathName;

            $resolved = $localeData[$routePathName] ?? null;

            if (\is_string($resolved)) {
                return $resolved;
            }

            // Fall through to routePath if routePathName didn't resolve
        }

        $routePath = $localeData['routePath'] ?? null;

        return \is_string($routePath) ? $routePath : null;
    }

    protected function getSlug(array $document, string $locale): ?string
    {
        if (!isset($document['localizations'][$locale])) {
            return null;
        }

        return $this->resolveRoutePath($document['localizations'][$locale]);
    }

    protected function getParentId(array $document, string $locale): ?string
    {
        // TODO page tree route support
        return null;
    }

    protected function getDefaultData(): array
    {
        return [
            'seoNoIndex' => false,
            'seoNoFollow' => false,
            'seoHideInSitemap' => false,
            'customizeWebspaceSettings' => false,
        ];
    }

    protected function insertDataRelationsToDimensionContent(array $document, ?string $locale, array $dimensionContent): void
    {
        parent::insertDataRelationsToDimensionContent($document, $locale, $dimensionContent);
        $this->insertOrUpdateAdditionalWebspaces($document, $locale, $dimensionContent);
    }

    /**
     * @param Document $document
     * @param DimensionContent $dimensionContent
     */
    private function insertOrUpdateAdditionalWebspaces(array $document, ?string $locale, array $dimensionContent): void
    {
        if (null === $locale) {
            return;
        }

        if (!isset($document['localizations'][$locale])) {
            return;
        }

        $additionalWebspaces = $document['localizations'][$locale]['additionalWebspaces'] ?? null;

        // Apply configured defaults when PHPCR has no explicit additional webspace settings.
        if (null === $additionalWebspaces && [] !== $this->defaultAdditionalWebspacesMap) {
            $additionalWebspaces = $this->defaultAdditionalWebspacesMap[$locale]
                ?? $this->defaultAdditionalWebspacesMap['default']
                ?? null;
        }

        if (null === $additionalWebspaces) {
            return;
        }

        $tableName = 'ar_article_dimension_content_additional_webspaces';

        $this->entityRepository->removeBy($tableName, [
            'article_dimension_content_id' => $dimensionContent['id'],
        ]);

        foreach ($additionalWebspaces as $webspace) {
            $this->entityRepository->insertOrUpdate(
                [
                    'article_dimension_content_id' => $dimensionContent['id'],
                    'name' => $webspace,
                ],
                $tableName,
                [
                    'article_dimension_content_id' => 'integer',
                    'name' => 'string',
                ],
            );
        }
    }

    protected function isRoutable(): bool
    {
        return true;
    }
}
