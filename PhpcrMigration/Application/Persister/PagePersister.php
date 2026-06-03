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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\InvalidPathException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\TitleTooLongException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * @phpstan-import-type Document from AbstractPersister
 * @phpstan-import-type DimensionContent from AbstractPersister
 */
class PagePersister extends AbstractPersister
{
    public function __construct(
        PropertyAccessorInterface $propertyAccessor,
        EntityRepositoryInterface $entityRepository,
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
        $data['navContexts'] = null;
        $data['internal_link'] = null;
        $data['external'] = null;

        return \array_filter($data, static fn ($entry) => null !== $entry);
    }

    protected function mapDimensionContentData(array $document, ?string $locale, array $data, bool $isLive): array
    {
        $data = parent::mapDimensionContentData($document, $locale, $data, $isLive);
        $data = $this->mapShadowLocaleData($document, $locale, $data);

        $data[$this->getDimensionContentEntityIdMappingName()] = $document['jcr']['uuid'];
        $data['locale'] = $locale;
        $data['stage'] = $isLive ? 'live' : 'draft';
        $data['workflowPlace'] = null === $locale ? null : (2 === ($data['workflowPlace'] ?? null) ? 'published' : 'draft');

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

        if (isset($document['localizations'][$locale])) {
            $url = $this->resolveTemplateUrl($document['localizations'][$locale]);
            if (null !== $url) {
                $templateData['url'] = $url;
            }
        }

        $data['templateData'] = $templateData;

        // Transform segments map to single segment value for current webspace
        if (isset($data['excerptSegment']) && \is_array($data['excerptSegment'])) {
            /** @var array{webspaceKey?: string} $suluData */
            $suluData = $document['sulu'];
            $webspaceKey = $suluData['webspaceKey'] ?? null;

            $data['excerptSegment'] = $webspaceKey && isset($data['excerptSegment'][$webspaceKey])
                ? $data['excerptSegment'][$webspaceKey]
                : null;
        }

        $data = $this->mapLinkData($document, $locale, $data);

        return $data;
    }

    /**
     * @param Document $document
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mapLinkData(array $document, ?string $locale, array $data): array
    {
        $data['linkProvider'] = null;
        $data['linkData'] = null;

        if (null === $locale) {
            return $data;
        }

        /** @var array<string, mixed> $localization */
        $localization = $document['localizations'][$locale] ?? [];

        // Shadow locales inherit link type from their shadow target: read target's localization instead.
        $isShadow = $localization['shadow-on'] ?? false;
        $shadowBase = $localization['shadow-base'] ?? null;
        if ($isShadow && \is_string($shadowBase) && isset($document['localizations'][$shadowBase])) {
            $localization = $document['localizations'][$shadowBase];
        }

        $nodeType = $localization['nodeType'] ?? 1;

        if (2 === $nodeType) {
            // Internal link
            $targetUuid = $localization['internal_link'] ?? null;
            $data['linkProvider'] = 'page'; // Sulu 2.6 did only support 'page' as internal link provider
            $data['linkData'] = null !== $targetUuid ? [
                'provider' => 'page',
                'href' => $targetUuid,
                'locale' => $locale,
            ] : null;
        } elseif (4 === $nodeType) {
            // External link
            $externalUrl = $localization['external'] ?? null;
            $data['linkProvider'] = 'external';
            $data['linkData'] = null !== $externalUrl ? [
                'provider' => 'external',
                'href' => $externalUrl,
                'locale' => $locale,
            ] : null;
        }

        return $data;
    }

    protected function insertDataRelationsToDimensionContent(array $document, ?string $locale, array $dimensionContent): void
    {
        parent::insertDataRelationsToDimensionContent($document, $locale, $dimensionContent);
        $this->insertOrUpdateNavigationContexts($document, $locale, $dimensionContent);
    }

    /**
     * @param Document $document
     * @param DimensionContent $dimensionContent
     */
    private function insertOrUpdateNavigationContexts(array $document, ?string $locale, array $dimensionContent): void
    {
        $navigationContexts = $document['localizations'][$locale]['navContexts'] ?? null;

        if (null === $navigationContexts) {
            return;
        }

        $navigationContextTableName = 'pa_page_dimension_content_navigation_contexts';
        // Remove all existing entries
        $this->entityRepository->removeBy(
            $navigationContextTableName,
            [
                'page_dimension_content_id' => $dimensionContent['id'],
            ],
        );

        foreach ($navigationContexts as $navigationContext) {
            $this->entityRepository->insertOrUpdate(
                [
                    'page_dimension_content_id' => $dimensionContent['id'],
                    'name' => $navigationContext,
                ],
                $navigationContextTableName,
                [
                    'page_dimension_content_id' => 'integer',
                    'name' => 'string',
                ],
            );
        }
    }

    public function supports(array $document): bool
    {
        return \in_array('sulu:page', $document['jcr']['mixinTypes'], true)
            || \in_array('sulu:home', $document['jcr']['mixinTypes'], true);
    }

    public static function getType(): string
    {
        return 'page';
    }

    public function getEntityTableName(): string
    {
        return 'pa_pages';
    }

    protected function getEntityTableTypes(): array
    {
        return [
            'uuid' => 'string',
            'parent_id' => 'string',
            'webspaceKey' => 'string',
            'lft' => 'integer',
            'rgt' => 'integer',
            'depth' => 'integer',
            'created' => 'datetime',
            'changed' => 'datetime',
            'idUsersCreator' => 'integer',
            'idUsersChanger' => 'integer',
        ];
    }

    protected function getEntityMapping(): array
    {
        return [
            '[uuid]' => '[jcr][uuid]',
            '[parent_id]' => '[sulu][parentId]',
            '[webspaceKey]' => '[sulu][webspaceKey]',
            '[created]' => '[sulu][created]',
            '[changed]' => '[sulu][changed]',
            '[idUsersCreator]' => '[sulu][creator]',
            '[idUsersChanger]' => '[sulu][changer]',
        ];
    }

    protected function getDimensionContentTableName(): string
    {
        return 'pa_page_dimension_contents';
    }

    protected function getDimensionContentTableTypes(): array
    {
        return [
            'author_id' => 'integer',
            'route_id' => 'integer',
            'title' => 'string',
            'stage' => 'string',
            'locale' => 'string',
            'ghostLocale' => 'string',
            'availableLocales' => 'json',
            'shadowLocale' => 'string',
            'shadowLocales' => 'json',
            'templateKey' => 'string',
            'templateData' => 'json',
            'seoData' => 'json',
            'seoNoIndex' => 'boolean',
            'seoNoFollow' => 'boolean',
            'seoHideInSitemap' => 'boolean',
            'excerptData' => 'json',
            'excerptSegment' => 'string',
            'authored' => 'datetime',
            'lastModified' => 'datetime',
            'workflowPlace' => 'string',
            'workflowPublished' => 'datetime',
            'linkProvider' => 'string',
            'linkData' => 'json',
            'idUsersCreator' => 'integer',
            'idUsersChanger' => 'integer',
        ];
    }

    protected function getDimensionContentMapping(): array
    {
        return [
            '[author_id]' => '[author]',
            '[authored]' => '[authored]',
            '[route_id]' => '[_route][id]',
            '[lastModified]' => '[changed]',
            '[title]' => '[title]',
            '[ghostLocale]' => '[ghostLocale]',
            '[availableLocales]' => '[availableLocales]',
            '[shadowLocale]' => '[shadowLocale]',
            '[shadowLocales]' => '[shadowLocales]',
            '[templateKey]' => '[template]',
            '[workflowPlace]' => '[state]',
            '[workflowPublished]' => '[published]',
            '[seoData]' => '[_seoData]',
            '[seoNoIndex]' => '[seo][noIndex]',
            '[seoNoFollow]' => '[seo][noFollow]',
            '[seoHideInSitemap]' => '[seo][hideInSitemap]',
            '[excerptData]' => '[_excerptData]',
            '[excerptSegment]' => '[excerpt][segments]',
            '[linkProvider]' => '[linkProvider]',
            '[linkData]' => '[linkData]',
        ];
    }

    protected function getDimensionContentEntityIdMappingName(): string
    {
        return 'pageUuid';
    }

    protected function getEntityResourceKey(): string
    {
        return 'pages';
    }

    protected function getDimensionContentExcerptCategoriesTableName(): string
    {
        return 'pa_page_dimension_content_excerpt_categories';
    }

    protected function getDimensionContentExcerptCategoriesIdName(): string
    {
        return 'page_dimension_content_id';
    }

    protected function getDimensionContentExcerptTagsTableName(): string
    {
        return 'pa_page_dimension_content_excerpt_tags';
    }

    protected function getDimensionContentExcerptTagsIdName(): string
    {
        return 'page_dimension_content_id';
    }

    protected function getDimensionContentExcerptAudienceTargetGroupsTableName(): string
    {
        return 'pa_page_dimension_content_excerpt_audience_target_groups';
    }

    protected function getDimensionContentExcerptAudienceTargetGroupsIdName(): string
    {
        return 'page_dimension_content_id';
    }

    protected function getSlug(array $document, string $locale): ?string
    {
        $localizedData = $document['localizations'][$locale];

        // Published pages: slug comes from the migrated route node.
        if (isset($localizedData[AbstractPersister::URL])) {
            return $localizedData[AbstractPersister::URL];
        }

        // Draft-only locales have no route node, so fall back to the page's own resource
        // locator (`i18n:{locale}-url`) so unpublished pages keep their route. A stale or
        // duplicated URL that collides with an existing route is skipped in createOrUpdateRoutes.
        $url = $localizedData['url'] ?? null;

        return \is_string($url) ? $url : null;
    }

    protected function getWebspace(array $document, string $locale): ?string
    {
        /** @var array{webspaceKey?: string} $data */
        $data = $document['sulu'];

        if (!isset($data['webspaceKey'])) {
            throw new InvalidPathException('webspaceKey');
        }

        return $data['webspaceKey'];
    }

    protected function getParentId(array $document, string $locale): ?string
    {
        /** @var array{parentId?: string} $data */
        $data = $document['sulu'];

        if (!\array_key_exists('parentId', $data)) {
            throw new InvalidPathException('parentId');
        }

        return $data['parentId'];
    }

    protected function getDefaultData(): array
    {
        return [
            'seoNoIndex' => false,
            'seoNoFollow' => false,
            'seoHideInSitemap' => false,
        ];
    }

    protected function isRoutable(): bool
    {
        return true;
    }
}
