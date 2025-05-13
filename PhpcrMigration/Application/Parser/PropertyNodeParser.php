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

class PropertyNodeParser implements NodeParserInterface
{
    public function __construct(
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * @return array{
     *     localizations: array<string, array<string, mixed>>,
     *     jcr: array<string, array<string,mixed>>,
     *     sulu: array<string, array<string,mixed>>,
     * }
     */
    public function parse(NodeInterface $node): array
    {
        $document = [
            'localizations' => [
                'null' => [], // required to always create the unlocalized dimension
            ],
            'sulu' => [],
            'jcr' => [],
        ];
        foreach ($node->getProperties() as $property) {
            $document = $this->parseProperty($property, $document);
        }

        /** @var array<string, array<string, mixed>> $localizations */
        $localizations = $document['localizations'];
        $document['localizations'] = $this->trimBlocksToLengths($localizations);

        /** @var array<string, array<string, mixed>> $localizations */
        $localizations = $document['localizations'];

        $lastKey = \array_key_last($localizations);

        if (null !== $lastKey) {
            /** @var array<string, mixed> $lastLocalization */
            $lastLocalization = $localizations[$lastKey];

            /** @var array<string, mixed> $suluDocument */
            $suluDocument = $document['sulu'];

            if (!\array_key_exists('created', $suluDocument) && isset($lastLocalization['created'])) {
                $suluDocument['created'] = $lastLocalization['created'];
            }

            if (!\array_key_exists('changed', $suluDocument) && isset($lastLocalization['changed'])) {
                $suluDocument['changed'] = $lastLocalization['changed'];
            }

            $document['sulu'] = $suluDocument;
        }

        /** @var array{
         *     localizations: array<string, array<string, mixed>>,
         *     jcr: array<string, array<string,mixed>>,
         *     sulu: array<string, array<string,mixed>>,
         * } $typedDocument
         */
        $typedDocument = $document;

        return $typedDocument;
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
        if (\is_string($value) && \json_validate($value) && ('' !== $value && '0' !== $value)) {
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
        } elseif (\str_starts_with($name, 'sec:')) {
            $name = \substr($name, 4);
            $propertyPath .= '[sec][' . $name . ']';
        } elseif (\str_starts_with($name, 'seo-')) {
            $name = \substr($name, 4);
            $propertyPath .= '[seo][' . $name . ']';
        } elseif (\str_starts_with($name, 'excerpt-')) {
            $name = \substr($name, 8);
            $propertyPath .= '[excerpt][' . $name . ']';
        } elseif (\str_contains($name, '#') || \str_ends_with($name, '-length')) {
            $propertyPath .= $this->parseBlockPropertyPath($name);
        } else {
            $propertyPath .= '[' . $name . ']';
        }

        return $propertyPath;
    }

    private function parseBlockPropertyPath(string $path): string
    {
        $segments = \explode('-', $path);
        $result = '';

        // First segment is always a block name
        $result .= '[' . $segments[0] . ']';
        $counter = \count($segments);

        for ($i = 1; $i < $counter; ++$i) {
            $segment = $segments[$i];

            if (\preg_match('/^(.+)#(\d+)$/', $segment, $matches)) {
                // For segments with index (like 'block#0')
                $name = $matches[1];
                $index = (int) $matches[2];

                $result .= '[' . $index . '][' . $name . ']';
            } else {
                // Regular segments without index (like type / length)
                $result .= '[' . $segment . ']';
            }
        }

        return $result;
    }

    /**
     * @param mixed[] $blocks
     *
     * @return mixed[]
     */
    private function trimBlocksToLengths(array $blocks): array
    {
        $maxLength = null;
        $isImageMap = false;
        foreach ($blocks as $index => $item) {
            if ('length' === $index) {
                /** @var int $maxLength */
                $maxLength = $item;
            }

            if ('imageId' === $index) {
                $isImageMap = true;
            }

            if (\is_array($item)) {
                $blocks[$index] = $this->trimBlocksToLengths($item);
            }
        }

        if (null !== $maxLength) {
            // remove length property
            unset($blocks['length']);

            if ($isImageMap) {
                $hotspots = [];
                foreach ($blocks as $index => $item) {
                    if (\is_array($item)) {
                        $hotspots[$index] = $item;
                        unset($blocks[$index]);
                    }
                }

                // trim hotspots to maxLength
                $hotspots = \array_slice($hotspots, 0, $maxLength);
                $blocks['hotspots'] = $hotspots;
            } else {
                // trim blocks to maxLength
                $blocks = \array_slice($blocks, 0, $maxLength);
            }
        }

        return $blocks;
    }
}
