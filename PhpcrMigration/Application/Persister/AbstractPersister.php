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

abstract class AbstractPersister implements PersisterInterface
{
    public function __construct(
        protected Connection $connection,
        protected PropertyAccessorInterface $propertyAccessor
    ) {
    }

    /**
     * @param mixed[] $document
     */
    public function persist(array $document, bool $isLive): void
    {
        if (false === $this->supports($document)) {
            throw new \Exception('Document type not supported');
        }

        if (!isset($document['jcr']['uuid'])) {
            throw new \Exception('UUID not found');
        }

        $this->connection->beginTransaction();
        $this->insertEntity($document);
        $this->insertDimensionContent($document, $isLive);
        $this->connection->commit();
    }

    /**
     * @param mixed[] $data
     * @param array<string, string> $mapping
     *
     * @return mixed[]
     */
    protected function mapDataViaMapping(array &$data, array $mapping, bool $setUsedValueNull = false): array
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

    /**
     * @param mixed[] $document
     */
    protected function insertEntity(array $document): void
    {
        $data = $this->mapDataViaMapping($document, $this->getEntityMapping());

        $this->insertOrUpdate(
            $data,
            $this->getEntityTableName(),
            $this->getEntityTableTypes(),
            [
                'uuid' => $data['uuid'],
            ]
        );
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
     * @param mixed[] $document
     */
    protected function insertDimensionContent(array $document, bool $isLive): void
    {
        //TODO unlocalized data
        /** @var mixed[] $localizations */
        $localizations = $document['localizations'] ?? [];
        foreach ($localizations as $locale => $localizedData) {
            $data = $this->mapDataViaMapping($localizedData, $this->getDimensionContentMapping(), true);
            $data = $this->mapData($document, $locale, $data, $isLive);
            $data = $this->mapExcerptImages($data);
            $data = $this->mapExcerptIcons($data);

            // remove known keys that do not belong to the templateData
            $localizedData = $this->removeNonTemplateData($localizedData);
            $data['templateData'] = $localizedData;

            $this->insertOrUpdate(
                $data,
                $this->getDimensionContentTableName(),
                $this->getDimensionContentTableTypes(), [
                    'articleUuid' => $data['articleUuid'], //TODO make dynamic
                    'locale' => $locale,
                    'stage' => $data['stage'],
                ]);
        }
    }

    /**
     * @param mixed[] $data
     * @param array<string, string> $types
     * @param array<string, mixed> $where
     */
    protected function insertOrUpdate(array $data, string $tableName, array $types, array $where): void
    {
        $exists = $this->connection->fetchAssociative(
            'SELECT * FROM ' . $tableName . ' WHERE ' . \implode(' AND ', \array_map(fn ($key) => $key . ' = :' . $key, \array_keys($where))),
            $where
        );

        match (null !== $exists) {
            true => $this->connection->update(
                $tableName,
                $data,
                $where,
                $types
            ),
            default => $this->connection->insert(
                $tableName,
                $data,
                $types
            ),
        };
    }

    /**
     * @param mixed[] $document
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    protected function mapData(array $document, string $locale, array $data, bool $isLive): array
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
        return $data;
    }

    abstract public function supports(array $document): bool;

    abstract public static function getType(): string;

    abstract protected function getEntityTableName(): string;

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
}
