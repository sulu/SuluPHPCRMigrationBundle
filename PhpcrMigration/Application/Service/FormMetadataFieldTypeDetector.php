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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service;

use PHPCR\NodeInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

class FormMetadataFieldTypeDetector implements FieldTypeDetectorInterface
{
    /**
     * @var array<string, string> Map of "type.templateKey.field[.blockType.field...]" => fieldType
     */
    private array $map = [];

    /**
     * @param array<string, mixed> $templatesConfiguration Value of the sulu_admin.templates.configuration parameter.
     *                                                     Keys are the type keys ("page", "article", "snippet", …).
     */
    public function __construct(
        private readonly ?MetadataProviderInterface $formMetadataProvider,
        private readonly array $templatesConfiguration = [],
    ) {
    }

    /**
     * Returns the field type for a given PHPCR property.
     *
     * @param string $type The content type (e.g. "page", "article", "snippet")
     * @param string $propertyName The PHPCR property name (e.g. "i18n:en-title", "i18n:en-blocks-code#0", "i18n:en-blocks-blocks#0-code#0")
     * @param string $locale The locale to use for looking up block types
     */
    public function getType(string $type, string $propertyName, NodeInterface $node, string $locale): ?string
    {
        if ([] === $this->map) {
            $this->buildTemplateFormIndex();
        }

        $templateKey = $this->getTemplateKey($node, $locale);
        if (null === $templateKey) {
            return null;
        }

        // Strip locale prefix from property name (e.g., "i18n:en-blocks-code#0" becomes "blocks-code#0")
        $plainPropertyName = $this->stripLocalePrefix($propertyName, $locale);

        // Transform PHPCR property name to mapping key
        $mappingKey = $this->transformPropertyNameToMappingKey($type, $templateKey, $plainPropertyName, $node, $locale);

        // Todo: For example created is missing...
        return $this->map[$mappingKey] ?? null;
    }

    /**
     * Strips the locale prefix from a PHPCR property name.
     * E.g., "i18n:en-blocks-code#0" becomes "blocks-code#0".
     */
    private function stripLocalePrefix(string $name, string $locale): string
    {
        $prefix = 'i18n:' . $locale . '-';
        if (\str_starts_with($name, $prefix)) {
            return \substr($name, \strlen($prefix));
        }

        return $name;
    }

    /**
     * Returns the full mapping for debugging purposes.
     *
     * @return array<string, string>
     */
    public function getMap(): array
    {
        if ([] === $this->map) {
            $this->buildTemplateFormIndex();
        }

        return $this->map;
    }

    /**
     * Gets the template key from the node.
     */
    private function getTemplateKey(NodeInterface $node, string $locale): ?string
    {
        $templateProperty = 'i18n:' . $locale . '-template';

        if (!$node->hasProperty($templateProperty)) {
            return null;
        }

        $value = $node->getPropertyValue($templateProperty);

        return \is_string($value) && '' !== $value ? $value : null;
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
        // Start with type.templateKey
        $keyParts = [$type, $templateKey];

        $segments = $this->parsePropertyName($propertyName);

        if ([] === $segments) {
            return \implode('.', $keyParts) . '.' . $propertyName;
        }

        // For simple properties without blocks
        if (1 === \count($segments) && null === $segments[0]['index']) {
            $keyParts[] = $segments[0]['field'];

            return \implode('.', $keyParts);
        }

        $phpcr_prefix = '';
        $count = \count($segments);

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
                    $typePropertyName = 'i18n:' . $locale . '-' . $phpcr_prefix . $field . '-type#' . $nextIndex;
                    $phpcr_prefix .= $field . '-' . $nextSegment['field'] . '#' . $nextIndex . '-';
                } else {
                    // Block field already incorporated into prefix — omit field name in type lookup
                    $typePropertyName = 'i18n:' . $locale . '-' . $phpcr_prefix . 'type#' . $nextIndex;
                    $phpcr_prefix .= $nextSegment['field'] . '#' . $nextIndex . '-';
                }

                if ($node->hasProperty($typePropertyName)) {
                    $blockType = $node->getPropertyValue($typePropertyName);
                    if (\is_string($blockType) && '' !== $blockType) {
                        $keyParts[] = $blockType;
                    }
                }
            }
        }

        // Add leaf property name
        $keyParts[] = $segments[$count - 1]['field'];

        return \implode('.', $keyParts);
    }

    /**
     * Parses a PHPCR property name into segments.
     *
     * @return array<array{field: string, index: int|null}>
     */
    private function parsePropertyName(string $propertyName): array
    {
        $segments = [];

        $remaining = $propertyName;

        while ('' !== $remaining) {
            // Try to match "field#index-" or "field#index" at the end or "field-" or "field" at the end
            if (\preg_match('/^([a-zA-Z_]\w*)#(\d+)(?:-(.*))?$/', $remaining, $matches)) {
                $segments[] = ['field' => $matches[1], 'index' => (int) $matches[2]];
                $remaining = $matches[3] ?? '';
            } elseif (\preg_match('/^([a-zA-Z_]\w*)(?:-(.*))?$/', $remaining, $matches)) {
                $segments[] = ['field' => $matches[1], 'index' => null];
                $remaining = $matches[2] ?? '';
            } else {
                // Can't parse, return empty and fallback
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
    private function buildTemplateFormIndex(): void
    {
        if (!$this->formMetadataProvider instanceof MetadataProviderInterface) {
            return;
        }

        foreach (\array_keys($this->templatesConfiguration) as $typeKey) {
            try {
                $metadata = $this->formMetadataProvider->getMetadata((string) $typeKey, 'en', []);
                // @phpstan-ignore-next-line
            } catch (\Throwable) {
                continue;
            }

            if ($metadata instanceof TypedFormMetadata) {
                foreach ($metadata->getForms() as $formKey => $formMetadata) {
                    $prefix = $typeKey . '.' . $formKey;
                    $this->processItems($formMetadata->getItems(), $prefix);
                }
            }
        }
    }

    /**
     * Recursively processes form items and adds them to the map.
     *
     * @param ItemMetadata[] $items
     * @param string $prefix Current path prefix (e.g. "page.default" or "page.default.blocks.text")
     */
    private function processItems(array $items, string $prefix): void
    {
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                // Sections don't add to the path, just process their children
                $this->processItems($item->getItems(), $prefix);
            } elseif ($item instanceof FieldMetadata) {
                $this->processFieldMetadata($item, $prefix);
            }
        }
    }

    /**
     * Processes a single field metadata and adds it to the map.
     */
    private function processFieldMetadata(FieldMetadata $field, string $prefix): void
    {
        $fieldPath = $prefix . '.' . $field->getName();

        if ('block' === $field->getType()) {
            // For blocks, iterate through each block type and process their items
            foreach ($field->getTypes() as $blockTypeKey => $blockTypeForm) {
                $blockPrefix = $fieldPath . '.' . $blockTypeKey;
                $this->processItems($blockTypeForm->getItems(), $blockPrefix);
            }
        } else {
            // Regular field - add to map
            $this->map[$fieldPath] = $field->getType();
        }
    }
}
