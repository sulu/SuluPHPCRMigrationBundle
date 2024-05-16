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

use Doctrine\DBAL\Connection;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class ArticlePersister extends AbstractPersister
{
    public function __construct(
        Connection $connection,
        PropertyAccessorInterface $propertyAccessor
    ) {
        parent::__construct($connection, $propertyAccessor);
    }

    protected function removeNonTemplateData(array $data): array
    {
        $data['seo'] = null;
        $data['excerpt'] = null;
        $data['routePath'] = null;
        $data['stage'] = null;

        return \array_filter($data); // TODO callback function mit null
    }

    protected function mapData(array $document, string $locale, array $data, bool $isLive): array
    {
        $data['articleUuid'] = $document['jcr']['uuid'];
        $data['locale'] = $locale;
        $data['stage'] = $isLive ? 'live' : 'draft';
        $data['title'] = \str_split((string) $data['title'], 64)[0];
        $data['workflowPlace'] = 2 === $data['workflowPlace'] ? 'published' : 'draft';

        return $data;
    }

    protected function insertOrUpdate(array $data, string $tableName, array $types, array $where): void
    {
        $exists = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $tableName . ' WHERE ' . \implode(' AND ', \array_map(fn ($key) => $key . ' = :' . $key, \array_keys($where))),
            $where
        );

        if ($exists) {
            $this->connection->update(
                $tableName,
                $data,
                $where,
                $types
            );

            return;
        }

        $this->connection->insert(
            $tableName,
            $data,
            $types
        );
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
}
