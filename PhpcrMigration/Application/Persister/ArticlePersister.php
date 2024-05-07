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

readonly class ArticlePersister implements PersisterInterface
{
    public function __construct(
        private Connection $connection,
        private PropertyAccessorInterface $propertyAccessor
    ) {
    }

    public function persist(array $document, bool $isLive): void
    {
        if (false === $this->supports($document)) {
            throw new \Exception('Document type not supported');
        }

        if (!isset($document['jcr']['uuid'])) {
            throw new \Exception('UUID not found');
        }

        $this->connection->beginTransaction();
        $this->insertArticle($document);
        $this->insertArticleDimensionContent($document, $isLive);
        $this->connection->commit();
    }

    private function mapData(array &$data, array $mapping, bool $setUsedValueNull = false): array
    {
        $mappedData = [];
        foreach ($mapping as $target => $source) {
            $this->propertyAccessor->setValue(
                $mappedData,
                $target,
                $this->propertyAccessor->getValue($data, $source)
            );
            // set data to null, so that it can be filtered out later
            if ($setUsedValueNull) {
                $this->propertyAccessor->setValue($data, $source, null);
            }
        }

        return $mappedData;
    }

    private function insertArticle(array $document): void
    {
        $mapping = $this->getEntityPathMapping();
        $data = $this->mapData($document, $mapping);

        $exists = $this->connection->fetchAssociative(
            'SELECT * FROM ar_articles WHERE uuid = :uuid',
            ['uuid' => $data['uuid']]
        );

        if (!$exists) {
            $this->connection->insert(self::getEntityTableName(), $data, self::getEntityTableTypes());

            return;
        }

        $this->connection->update(
            self::getEntityTableName(),
            $data,
            ['uuid' => $data['uuid']],
            self::getEntityTableTypes()
        );
    }

    private function insertArticleDimensionContent(array $document, bool $isLive): void
    {
        foreach ($document['localizations'] as $locale => $localizedData) {
            $data = $this->mapData($document, $this->getArticleDimensionContentMapping($locale));
            $data['locale'] = $locale;
            $data['stage'] = $isLive ? 'live' : 'draft';
            $data['title'] = \str_split((string) $data['title'], 64)[0];

            $data['workflowPlace'] = 2 === $data['workflowPlace'] ? 'published' : 'draft';

            if ($data['excerptImageId'] ?? null) {
                $data['excerptImageId'] = $data['excerptImageId']['ids'][0] ?? null;
            }

            if ($data['excerptIconId'] ?? null) {
                $data['excerptIconId'] = $data['excerptIconId']['ids'][0] ?? null;
            }

            // remove known keys that do not belong to the templateData
            $document['localizations'][$locale] = \array_filter($document['localizations'][$locale]);
            unset($document['localizations'][$locale]['seo']);
            unset($document['localizations'][$locale]['excerpt']);
            unset($document['localizations'][$locale]['routePath']);
            unset($document['localizations'][$locale]['stage']);
            $data['templateData'] = $document['localizations'][$locale];

            $exists = $this->connection->fetchAssociative(
                'SELECT * FROM ar_article_dimension_contents WHERE articleUuid = :articleUuid AND locale = :locale AND stage = :stage',
                [
                    'articleUuid' => $document['jcr']['uuid'],
                    'locale' => $locale,
                    'stage' => $data['stage'],
                ]
            );

            if ($exists) {
                $this->connection->update(
                    self::getDimensionContentTableName(),
                    $data,
                    [
                        'articleUuid' => $document['jcr']['uuid'],
                        'locale' => $locale,
                        'stage' => $data['stage'],
                    ],
                    self::getDimensionContentTableTypes()
                );

                continue;
            }

            $this->connection->insert(
                self::getDimensionContentTableName(),
                $data,
                self::getDimensionContentTableTypes()
            );
        }
    }

    public function supports(array $document): bool
    {
        return \in_array('sulu:article', $document['jcr']['mixinTypes']);
    }

    public static function getType(): string
    {
        return 'article';
    }

    public static function getEntityTableName(): string
    {
        return 'ar_articles';
    }

    public static function getEntityTableTypes(): array
    {
        return [
            'uuid' => 'string',
            'created' => 'datetime',
            'changed' => 'datetime',
        ];
    }

    public static function getDimensionContentTableName(): string
    {
        return 'ar_article_dimension_contents';
    }

    public static function getDimensionContentTableTypes(): array
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

    public function getEntityPathMapping(): array
    {
        return [
            '[uuid]' => '[jcr][uuid]',
            '[created]' => '[sulu][created]',
            '[changed]' => '[sulu][changed]',
        ];
    }

    public function getArticleDimensionContentMapping(string $locale): array
    {
        return [
            '[author_id]' => '[localizations][' . $locale . '][author]',
            '[authored]' => '[localizations][' . $locale . '][authored]',
            '[title]' => '[localizations][' . $locale . '][title]',
            '[ghostLocale]' => '[localizations][' . $locale . '][ghostLocale]',
            '[availableLocales]' => '[localizations][' . $locale . '][availableLocales]',
            '[templateKey]' => '[localizations][' . $locale . '][template]',
            '[workflowPlace]' => '[localizations][' . $locale . '][state]',
            '[workflowPublished]' => '[localizations][' . $locale . '][published]',
            '[articleUuid]' => '[jcr][uuid]',
            '[seoTitle]' => '[localizations][' . $locale . '][seo][title]',
            '[seoDescription]' => '[localizations][' . $locale . '][seo][description]',
            '[seoKeywords]' => '[localizations][' . $locale . '][seo][keywords]',
            '[seoCanonicalUrl]' => '[localizations][' . $locale . '][seo][canonicalUrl]',
            '[seoNoIndex]' => '[localizations][' . $locale . '][seo][noIndex]',
            '[seoNoFollow]' => '[localizations][' . $locale . '][seo][noFollow]',
            '[seoHideInSitemap]' => '[localizations][' . $locale . '][seo][hideInSitemap]',
            '[excerptTitle]' => '[localizations][' . $locale . '][excerpt][title]',
            '[excerptMore]' => '[localizations][' . $locale . '][excerpt][more]',
            '[excerptDescription]' => '[localizations][' . $locale . '][excerpt][description]',
            '[excerptImageId]' => '[localizations][' . $locale . '][excerpt][images]',
            '[excerptIconId]' => '[localizations][' . $locale . '][excerpt][icon]',
        ];
    }
}
