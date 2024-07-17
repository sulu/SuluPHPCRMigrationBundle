<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser;

use Jackalope\Property;
use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class NodeParser
{
    public function __construct(
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @return mixed[]
     */
    public function parse(NodeInterface $node): array
    {
        $document = [
            'localizations' => [
                'null' => [], // required to always create the unlocalized dimension
            ],
        ];
        foreach ($node->getProperties() as $property) {
            $document = $this->parseProperty($property, $document);
        }

        return $document;
    }

    /**
     * @param mixed[] $document
     *
     * @return mixed[]
     */
    private function parseProperty(PropertyInterface $property, array $document): array
    {
        $name = $property->getName();
        $value = $this->resolvePropertyValue($property);
        $propertyPath = $this->getLocalizedPath($name);
        $propertyPath = $this->getPropertyPath($propertyPath, $name);
        $this->propertyAccessor->setValue(
            $document,
            $propertyPath,
            $value
        );

        return $document;
    }

    private function isUnLocalizedProperty(string $name): bool
    {
        return !\str_contains($name, ':');
    }

    private function resolvePropertyValue(PropertyInterface $property): mixed
    {
        $value = $property instanceof Property ? $property->getValueForStorage() : $property->getValue();
        if (\is_string($value) && json_validate($value) && ('' !== $value && '0' !== $value)) {
            return \json_decode($value, true);
        }

        return $value;
    }

    private function getLocalizedPath(string &$name): string
    {
        $propertyPath = '';
        if (\str_starts_with($name, 'i18n:')) {
            $localizationOffset = 5;
            $firstDashPosition = \strpos($name, '-', $localizationOffset);
            $locale = \substr($name, 5, $firstDashPosition - $localizationOffset);
            $propertyPath .= '[localizations][' . $locale . ']';
            $name = \substr($name, $firstDashPosition + 1);
        } elseif ($this->isUnLocalizedProperty($name)) {
            $propertyPath .= '[localizations][null]';
        }

        return $propertyPath;
    }

    private function getPropertyPath(string $propertyPath, string $name): string
    {
        if (\str_starts_with($name, 'jcr:')) {
            $name = \substr($name, 4);
            $propertyPath .= '[jcr][' . $name . ']';
        } elseif (\str_starts_with($name, 'sulu:')) {
            $name = \substr($name, 5);
            $propertyPath .= '[sulu][' . $name . ']';
        } elseif (\str_starts_with($name, 'seo-')) {
            $name = \substr($name, 4);
            $propertyPath .= '[seo][' . $name . ']';
        } elseif (\str_starts_with($name, 'excerpt-')) {
            $name = \substr($name, 8);
            $propertyPath .= '[excerpt][' . $name . ']';
        } elseif (\preg_match('/^(.+)#(\d+)$/', $name, $matches)) {
            $name = $matches[1];
            $index = (int) $matches[2];
            [$blocksKey, $type] = \explode('-', $name);
            $propertyPath .= '[' . $blocksKey . '][' . $index . '][' . $type . ']';
        } else {
            $propertyPath .= '[' . $name . ']';
        }

        return $propertyPath;
    }
}
