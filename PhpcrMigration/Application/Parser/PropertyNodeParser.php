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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\FieldTypeDetectorInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service\LocaleDiscoveryService;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class PropertyNodeParser implements NodeParserInterface
{
    public const SKIP_DECODE_FIELD_TYPES = ['text_line', 'text_area'];

    public function __construct(
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly LocaleDiscoveryService $localeDiscoveryService,
        private readonly FieldTypeDetectorInterface $plainTextFieldDetector,
    ) {
    }

    /**
     * @return array{
     *     localizations: array<string, array<string, mixed>>,
     *     jcr: array<string, array<string,mixed>>,
     *     sulu: array<string, array<string,mixed>>,
     * }|array{}
     */
    public function parse(NodeInterface $node, string $documentType): array
    {
        if ('snippet_area' === $documentType) {
            return [];
        }

        $document = [
            'localizations' => [
                'null' => [], // required to always create the unlocalized dimension
            ],
            'sulu' => [],
            'jcr' => [],
        ];
        $discoveredLocales = $this->localeDiscoveryService->discoverLocales($node);

        foreach ($node->getProperties() as $property) {
            $locale = $this->extractLocaleFromPropertyName($property->getName(), $discoveredLocales);

            $skipDecode = false;
            if (null !== $locale) {
                $fieldType = $this->plainTextFieldDetector->getType($documentType, $property->getName(), $node, $locale);
                $skipDecode = \in_array($fieldType, self::SKIP_DECODE_FIELD_TYPES, true);
            }

            $document = $this->parseProperty($property, $document, $discoveredLocales, $skipDecode);
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
     * @param string[] $knownLocales
     *
     * @return mixed[]
     */
    private function parseProperty(PropertyInterface $property, array $document, array $knownLocales, bool $skipDecode = false): array
    {
        $name = $property->getName();
        $value = $this->resolvePropertyValue($property, $skipDecode);
        $propertyPath = $this->getLocalizedPath($name, $knownLocales);
        $propertyPath = $this->getPropertyPath($propertyPath, $name);

        if ($this->isBlockTypeProperty($name) && (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString')))) {
            $value = (string) $value;
        }

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

    private function isBlockTypeProperty(string $name): bool
    {
        return \str_contains($name, '-type#');
    }

    private function resolvePropertyValue(PropertyInterface $property, bool $skipDecode = false): mixed
    {
        $value = $property instanceof Property ? $property->getValueForStorage() : $property->getValue();
        if (\is_string($value) && '' !== $value && '0' !== $value) {
            if ($skipDecode) {
                return $value;
            }

            $decoded = \json_decode($value, true);
            if (\JSON_ERROR_NONE === \json_last_error()) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * @param string[] $locales
     */
    private function getLocalizedPath(string &$name, array $locales = []): string
    {
        if (\str_starts_with($name, 'i18n:')) {
            $locale = $this->extractLocaleFromPropertyName($name, $locales);
            if (null !== $locale) {
                $name = \substr($name, \strlen('i18n:' . $locale . '-'));

                return '[localizations][' . $locale . ']';
            }
        } elseif ($this->isUnLocalizedProperty($name)) {
            return '[localizations][null]';
        }

        return '';
    }

    /**
     * Extracts the locale from a PHPCR property name.
     *
     * @param string[] $locales
     */
    private function extractLocaleFromPropertyName(string $name, array $locales): ?string
    {
        if (!\str_starts_with($name, 'i18n:')) {
            return null;
        }

        $afterPrefix = \substr($name, 5);
        $sortedLocales = $locales;
        \usort($sortedLocales, fn ($a, $b) => \strlen($b) - \strlen($a));

        foreach ($sortedLocales as $locale) {
            if (\str_starts_with($afterPrefix, $locale . '-')) {
                return $locale;
            }
        }

        return null;
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
            // Special handling for segments which are stored as separate properties per webspace
            // e.g., excerpt-segments-sulu_io becomes [excerpt][segments][sulu_io]
            if (\str_starts_with($name, 'segments-')) {
                $webspaceKey = \substr($name, 9); // Remove 'segments-' prefix
                $propertyPath .= '[excerpt][segments][' . $webspaceKey . ']';
            } else {
                $propertyPath .= '[excerpt][' . $name . ']';
            }
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

                // sort by index and trim blocks to maxLength
                \ksort($hotspots, \SORT_NUMERIC);
                $hotspots = \array_slice($hotspots, 0, $maxLength);
                $blocks['hotspots'] = $hotspots;
            } else {
                // sort by index and trim blocks to maxLength
                \ksort($blocks, \SORT_NUMERIC);
                $blocks = \array_slice($blocks, 0, $maxLength);
            }
        }

        return $blocks;
    }
}
