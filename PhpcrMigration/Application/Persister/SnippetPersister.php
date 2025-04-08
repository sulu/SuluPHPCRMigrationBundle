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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class SnippetPersister extends AbstractPersister
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

        $data['excerpt'] = null;
        $data['stage'] = null;
        $data['suluPages'] = null;
        $data['author'] = null;
        $data['authored'] = null;
        $data['template'] = null;
        $data['state'] = null;
        $data['availableLocales'] = null;

        return \array_filter($data, static fn ($entry) => null !== $entry);
    }

    protected function mapDimensionContentData(array $document, ?string $locale, array $data, bool $isLive): array
    {
        $data = parent::mapDimensionContentData($document, $locale, $data, $isLive);

        $data[$this->getDimensionContentEntityIdMappingName()] = $document['jcr']['uuid'];
        $data['locale'] = $locale;
        $data['stage'] = $isLive ? 'live' : 'draft';
        // snippets are always published, because in Sulu 2.6 there is no draft stage for snippets
        $data['workflowPlace'] = 'published';

        if (isset($data['title'])) {
            // TODO error collector with titles that were too long
            $data['title'] = \str_split((string) $data['title'], 64)[0];
            $data['templateData']['title'] = $data['title'];
        }

        return $data;
    }

    public function supports(array $document): bool
    {
        return \in_array('sulu:snippet', $document['jcr']['mixinTypes']);
    }

    public static function getType(): string
    {
        return 'snippet';
    }

    protected function getEntityTableName(): string
    {
        return 'sn_snippets';
    }

    protected function getEntityTableTypes(): array
    {
        return [
            'uuid' => 'string',
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
            '[created]' => '[sulu][created]',
            '[changed]' => '[sulu][changed]',
        ];
    }

    protected function getDimensionContentTableName(): string
    {
        return 'sn_snippet_dimension_contents';
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
            'excerptTitle' => 'string',
            'excerptMore' => 'string',
            'excerptDescription' => 'string',
            'excerptImageId' => 'integer',
            'excerptIconId' => 'integer',
            'authored' => 'datetime',
            'workflowPlace' => 'string',
            'workflowPublished' => 'datetime',
        ];
    }

    protected function getDimensionContentMapping(): array
    {
        //TODO
        return [
            '[author_id]' => '[author]',
            '[authored]' => '[authored]',
            '[title]' => '[title]',
            '[ghostLocale]' => '[ghostLocale]',
            '[availableLocales]' => '[availableLocales]',
            '[templateKey]' => '[template]',
            '[workflowPlace]' => '[state]',
            '[workflowPublished]' => '[published]',
            '[excerptTitle]' => '[excerpt][title]',
            '[excerptMore]' => '[excerpt][more]',
            '[excerptDescription]' => '[excerpt][description]',
            '[excerptImageId]' => '[excerpt][images]',
            '[excerptIconId]' => '[excerpt][icon]',
        ];
    }

    protected function getDimensionContentEntityIdMappingName(): string
    {
        return 'snippetUuid';
    }

    protected function getEntityResourceKey(): string
    {
        return 'snippets';
    }

    protected function getDimensionContentExcerptCategoriesTableName(): string
    {
        return 'sn_snippet_dimension_content_excerpt_categories';
    }

    protected function getDimensionContentExcerptCategoriesIdName(): string
    {
        return 'snippet_dimension_content_id';
    }

    protected function getDimensionContentExcerptTagsTableName(): string
    {
        return 'sn_snippet_dimension_content_excerpt_tags';
    }

    protected function getDimensionContentExcerptTagsIdName(): string
    {
        return 'snippet_dimension_content_id';
    }

    protected function isRoutable(): bool
    {
        return false;
    }
}
