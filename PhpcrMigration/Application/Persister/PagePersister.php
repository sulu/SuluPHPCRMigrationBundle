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

        return \array_filter($data, static fn ($entry) => null !== $entry);
    }

    protected function mapDimensionContentData(array $document, ?string $locale, array $data, bool $isLive): array
    {
        $data = parent::mapDimensionContentData($document, $locale, $data, $isLive);

        $data[$this->getDimensionContentEntityIdMappingName()] = $document['jcr']['uuid'];
        $data['locale'] = $locale;
        $data['stage'] = $isLive ? 'live' : 'draft';
        $data['workflowPlace'] = 2 === ($data['workflowPlace'] ?? null) ? 'published' : 'draft';

        if (isset($data['title'])) {
            // TODO error collector with titles that were too long
            $data['title'] = \str_split((string) $data['title'], 64)[0];
            $data['templateData']['title'] = $data['title'];
        }

        if (isset($document['localizations'][$locale]['routePathName']) && isset($document['localizations'][$locale]['routePath'])) {
            $routePathName = $document['localizations'][$locale]['routePathName'];
            $routePathName = \str_starts_with($routePathName, 'i18n:') ? \explode('-', $routePathName, 2)[1] : $routePathName;
            // check routePathName property and fallback to routePath
            $routePath = $document['localizations'][$locale][$routePathName] ?? $document['localizations'][$locale]['routePath'];

            // content bundle is only compatible with "url"
            $data['templateData']['url'] = $routePath; // is used in the content bundle
            $data['templateData'][$routePath] = $routePath; // can still be used in the template TODO
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

    protected function getEntityTableName(): string
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
        ];
    }

    protected function getDimensionContentTableName(): string
    {
        return 'pa_page_dimension_contents';
    }

    protected function getDimensionContentTableTypes(): array
    {
        // TODO shadow?
        return [
            'author_id' => 'integer',
            'title' => 'string',
            'stage' => 'string',
            'locale' => 'string',
            'ghostLocale' => 'string',
            'availableLocales' => 'json',
            'templateKey' => 'string',
            'templateData' => 'json',
            'seoTitle' => 'string',
            'seoDescription' => 'string',
            'seoKeywords' => 'string',
            'seoCanonicalUrl' => 'string',
            'seoNoIndex' => 'boolean',
            'seoNoFollow' => 'boolean',
            'seoHideInSitemap' => 'boolean',
            'excerptTitle' => 'string',
            'excerptMore' => 'string',
            'excerptDescription' => 'string',
            'excerptImageId' => 'integer',
            'excerptIconId' => 'integer',
            'authored' => 'datetime',
            'lastModified' => 'datetime',
            'workflowPlace' => 'string',
            'workflowPublished' => 'datetime',
        ];
    }

    protected function getDimensionContentMapping(): array
    {
        // TODO
        return [
            '[author_id]' => '[author]',
            '[authored]' => '[authored]',
            '[lastModified]' => '[changed]',
            '[title]' => '[title]',
            '[ghostLocale]' => '[ghostLocale]',
            '[availableLocales]' => '[availableLocales]',
            '[templateKey]' => '[template]',
            '[workflowPlace]' => '[state]',
            '[workflowPublished]' => '[published]',
            '[seoTitle]' => '[seo][title]',
            '[seoDescription]' => '[seo][description]',
            '[seoKeywords]' => '[seo][keywords]',
            '[seoCanonicalUrl]' => '[seo][canonicalUrl]',
            '[seoNoIndex]' => '[seo][noIndex]',
            '[seoNoFollow]' => '[seo][noFollow]',
            '[seoHideInSitemap]' => '[seo][hideInSitemap]',
            '[excerptTitle]' => '[excerpt][title]',
            '[excerptMore]' => '[excerpt][more]',
            '[excerptDescription]' => '[excerpt][description]',
            '[excerptImageId]' => '[excerpt][images]',
            '[excerptIconId]' => '[excerpt][icon]',
        ];
    }

    protected function getDimensionContentEntityIdMappingName(): string
    {
        return 'pageUuid';
    }

    protected function getEntityClassName(): string
    {
        return 'Sulu\Page\Domain\Model\PageInterface';
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

    protected function getPath(array $document, string $locale): string
    {
        $localizedData = $document['localizations'][$locale];

        if (!isset($localizedData['url'])) {
            throw new InvalidPathException('url');
        }

        return $localizedData['url'];
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
