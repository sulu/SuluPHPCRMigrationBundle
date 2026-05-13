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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector;

use PHPCR\NodeInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor\LocaleExtractor;

class FormMetadataFieldTypeDetector implements FieldTypeDetectorInterface
{
    /**
     * @var array<string, string> Map of "type.templateKey.field[.blockType.field...]" => fieldType
     */
    private array $map = [];

    private ?string $cachedTemplateNodeIdentifier = null;

    /**
     * @var array<string, string|null> Per-locale template keys for the currently-cached node
     */
    private array $cachedTemplateKeysForNode = [];

    /**
     * @param array<string, mixed> $templatesConfiguration Value of the sulu_admin.templates.configuration parameter.
     *                                                     Keys are the type keys ("page", "article", "snippet", …).
     */
    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly LocaleExtractor $localeExtractor,
        private readonly array $templatesConfiguration = [],
    ) {
    }

    /**
     * Returns the field type for a given PHPCR property.
     *
     * @param string $documentType The content type (e.g. "page", "article", "snippet")
     * @param string $propertyName The PHPCR property name (e.g. "i18n:en-title", "i18n:en-blocks-code#0", "i18n:en-blocks-blocks#0-code#0")
     */
    public function getType(string $documentType, string $propertyName, NodeInterface $node): ?string
    {
        $locale = $this->resolveLocale($propertyName, $node);
        if (null === $locale) {
            return null;
        }

        if ([] === $this->map) {
            // Form metadata is locale-independent in structure, so the map can be built once
            // with any available locale and shared across subsequent calls.
            $this->buildTemplateFormIndex($locale);
        }

        $templateKey = $this->getTemplateKey($node, $locale);
        if (null === $templateKey) {
            return null;
        }

        $plainPropertyName = $this->localeExtractor->stripPrefix($propertyName, $locale);
        $mappingKey = $this->transformPropertyNameToMappingKey(
            $documentType,
            $templateKey,
            $plainPropertyName,
            $node,
            $locale,
        );

        return $this->map[$mappingKey] ?? null;
    }

    private function resolveLocale(string $propertyName, NodeInterface $node): ?string
    {
        $matched = $this->localeExtractor->matchLocale($propertyName, $node);
        if (null !== $matched) {
            return $matched;
        }

        // Fallback: use any known locale to look up the (locale-independent)
        // template structure for unlocalized properties.
        return $this->localeExtractor->firstLocale($node);
    }

    /**
     * Sulu pages, articles and snippets store their template under `i18n:{locale}-template`.
     * Document types that keep the template on a non-localized property are not supported here:
     * `getType()` returns null for them and the property falls back to JSON decoding.
     */
    private function getTemplateKey(NodeInterface $node, string $locale): ?string
    {
        $nodeIdentifier = $node->getIdentifier();
        if ($nodeIdentifier !== $this->cachedTemplateNodeIdentifier) {
            $this->cachedTemplateNodeIdentifier = $nodeIdentifier;
            $this->cachedTemplateKeysForNode = [];
        }

        if (\array_key_exists($locale, $this->cachedTemplateKeysForNode)) {
            return $this->cachedTemplateKeysForNode[$locale];
        }

        $templateProperty = LocaleExtractor::I18N_PREFIX . $locale . '-template';

        if (!$node->hasProperty($templateProperty)) {
            return $this->cachedTemplateKeysForNode[$locale] = null;
        }

        $value = $node->getPropertyValue($templateProperty);

        return $this->cachedTemplateKeysForNode[$locale] = \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Transforms a PHPCR property name like "blocks-code#0" or "blocks-blocks#0-code#0"
     * into a mapping key like "page.default.blocks.text.code" or "page.default.blocks.nested.blocks.code-step.code".
     */
    private function transformPropertyNameToMappingKey(
        string $type,
        string $templateKey,
        string $propertyName,
        NodeInterface $node,
        string $locale,
    ): string {
        $keyParts = [$type, $templateKey];

        $segments = $this->parsePropertyName($propertyName);

        if ([] === $segments) {
            return \implode('.', $keyParts) . '.' . $propertyName;
        }

        if (1 === \count($segments) && null === $segments[0]['index']) {
            $keyParts[] = $segments[0]['field'];

            return \implode('.', $keyParts);
        }

        $phpcrPrefix = '';
        $count = \count($segments);
        $localePrefix = LocaleExtractor::I18N_PREFIX . $locale . '-';

        for ($i = 0; $i < $count - 1; ++$i) {
            $segment = $segments[$i];
            $nextSegment = $segments[$i + 1];
            $field = $segment['field'];
            $currentIndex = $segment['index'];
            $nextIndex = $nextSegment['index'];

            $keyParts[] = $field;

            if (null !== $nextIndex) {
                if (null === $currentIndex) {
                    // Block field not yet in prefix — include field name in type lookup
                    $typePropertyName = $localePrefix . $phpcrPrefix . $field . '-type#' . $nextIndex;
                    $phpcrPrefix .= $field . '-' . $nextSegment['field'] . '#' . $nextIndex . '-';
                } else {
                    // Block field already incorporated into prefix — omit field name in type lookup
                    $typePropertyName = $localePrefix . $phpcrPrefix . 'type#' . $nextIndex;
                    $phpcrPrefix .= $nextSegment['field'] . '#' . $nextIndex . '-';
                }

                if ($node->hasProperty($typePropertyName)) {
                    $blockType = $node->getPropertyValue($typePropertyName);
                    if (\is_string($blockType) && '' !== $blockType) {
                        $keyParts[] = $blockType;
                    }
                }
            }
        }

        $keyParts[] = $segments[$count - 1]['field'];

        return \implode('.', $keyParts);
    }

    /**
     * @return array<array{field: string, index: int|null}>
     */
    private function parsePropertyName(string $propertyName): array
    {
        $segments = [];

        $remaining = $propertyName;

        while ('' !== $remaining) {
            // Try to match "field#index-" or "field#index" or "field-" or "field" at the end
            if (\preg_match('/^([a-zA-Z_]\w*)#(\d+)(?:-(.*))?$/', $remaining, $matches)) {
                $segments[] = ['field' => $matches[1], 'index' => (int) $matches[2]];
                $remaining = $matches[3] ?? '';
            } elseif (\preg_match('/^([a-zA-Z_]\w*)(?:-(.*))?$/', $remaining, $matches)) {
                $segments[] = ['field' => $matches[1], 'index' => null];
                $remaining = $matches[2] ?? '';
            } else {
                return [];
            }
        }

        return $segments;
    }

    /**
     * Builds a master map of all field types indexed by their full path.
     *
     * Format: "type.templateKey.fieldName" for simple fields
     *         "type.templateKey.blockField.blockType.subField" for block fields
     *         "type.templateKey.blockField.blockType.nestedBlock.nestedType.subField" for nested blocks
     */
    private function buildTemplateFormIndex(string $locale): void
    {
        foreach (\array_keys($this->templatesConfiguration) as $typeKey) {
            $metadata = $this->formMetadataProvider->getMetadata((string) $typeKey, $locale, []);

            // Only TypedFormMetadata (page/article/snippet) is indexed. Other metadata shapes
            // contribute nothing to the map, so getType() returns null for their properties
            // and they keep the legacy JSON-decoding behaviour.
            if ($metadata instanceof TypedFormMetadata) {
                foreach ($metadata->getForms() as $formKey => $formMetadata) {
                    $prefix = $typeKey . '.' . $formKey;
                    $this->processItems($formMetadata->getItems(), $prefix);
                }
            }
        }
    }

    /**
     * @param ItemMetadata[] $items
     * @param string $prefix Current path prefix (e.g. "page.default" or "page.default.blocks.text")
     */
    private function processItems(array $items, string $prefix): void
    {
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                $this->processItems($item->getItems(), $prefix);
            } elseif ($item instanceof FieldMetadata) {
                $this->processFieldMetadata($item, $prefix);
            }
        }
    }

    private function processFieldMetadata(FieldMetadata $field, string $prefix): void
    {
        $fieldPath = $prefix . '.' . $field->getName();

        if ('block' === $field->getType()) {
            foreach ($field->getTypes() as $blockTypeKey => $blockTypeForm) {
                $blockPrefix = $fieldPath . '.' . $blockTypeKey;
                $this->processItems($blockTypeForm->getItems(), $blockPrefix);
            }
        } else {
            $this->map[$fieldPath] = $field->getType();
        }
    }
}
