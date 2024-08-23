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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\InvalidPathException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class ArticlePersister extends AbstractPersister
{
    public function __construct(
        PropertyAccessorInterface $propertyAccessor,
        EntityRepositoryInterface $entityRepository
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

        return \array_filter($data, static fn ($entry) => null !== $entry);
    }

    protected function mapData(array $document, ?string $locale, array $data, bool $isLive): array
    {
        $data = parent::mapData($document, $locale, $data, $isLive);

        $data[$this->getDimensionContentEntityIdMappingName()] = $document['jcr']['uuid'];
        $data['locale'] = $locale;
        $data['stage'] = $isLive ? 'live' : 'draft';
        $data['workflowPlace'] = 2 === ($data['workflowPlace'] ?? null) ? 'published' : 'draft';

        if (isset($data['title'])) {
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

    public function supports(array $document): bool
    {
        return \in_array('sulu:article', $document['jcr']['mixinTypes']);
    }

    public static function getType(): string
    {
        return 'article';
    }

    protected function getEntityTableName(): string
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
            'title' => 'string',
            'locale' => 'string',
            'ghostLocale' => 'string',
            'availableLocales' => 'json',
            'templateKey' => 'string',
            'stage' => 'string',
            'workflowPlace' => 'string',
            'workflowPublished' => 'datetime',
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
            'templateData' => 'json',
        ];
    }

    protected function getDimensionContentMapping(): array
    {
        return [
            '[author_id]' => '[author]',
            '[authored]' => '[authored]',
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
        return 'articleUuid';
    }

    protected function getEntityClassName(): string
    {
        return 'Sulu\Article\Domain\Model\ArticleInterface';
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

    protected function getPath(array $document, string $locale): string
    {
        $localizedData = $document['localizations'][$locale];

        if (!isset($localizedData['routePath'])) {
            throw new InvalidPathException('routePath');
        }

        return $localizedData['routePath'];
    }

    protected function getDefaultData(): array
    {
        return [
            'seoNoIndex' => false,
            'seoNoFollow' => false,
            'seoHideInSitemap' => false,
        ];
    }
}
