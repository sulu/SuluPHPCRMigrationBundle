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

use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class NodeParser
{
    public function __construct(
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function parse(NodeInterface $node): array
    {
        $document = [];
        foreach ($node->getProperties() as $property) {
            $document = $this->parseProperty($property, $document);
        }

        return $document;
    }

    private function parseProperty(PropertyInterface $property, array $document): array
    {
        $name = $property->getName();
        $value = $property->getValue();
        $propertyPath = $this->getLocalizedPath($name);
        $propertyPath = $this->getPropertyPath($propertyPath, $name);
        $this->propertyAccessor->setValue(
            $document,
            $propertyPath,
            $value
        );

        return $document;
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
        }

        return $propertyPath;
    }

    private function getPropertyPath(string $propertyPath, $name): string
    {
        if (\str_starts_with((string) $name, 'jcr:')) {
            $name = \substr((string) $name, 4);
            $propertyPath .= '[jcr][' . $name . ']';
        } elseif (\str_starts_with((string) $name, 'sulu:')) {
            $name = \substr((string) $name, 5);
            $propertyPath .= '[sulu][' . $name . ']';
        } elseif (\str_starts_with((string) $name, 'seo-')) {
            $name = \substr((string) $name, 4);
            $propertyPath .= '[seo][' . $name . ']';
        } elseif (\str_starts_with((string) $name, 'excerpt-')) {
            $name = \substr((string) $name, 8);
            $propertyPath .= '[excerpt][' . $name . ']';
        } elseif (\preg_match('/^(.+)#(\d+)$/', (string) $name, $matches)) {
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
