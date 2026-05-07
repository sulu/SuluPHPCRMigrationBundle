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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Resolver;

use Jackalope\Property;
use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector\FieldTypeDetectorInterface;

final class PropertyValueResolver
{
    private const SKIP_DECODE_FIELD_TYPES = ['text_line', 'text_area'];

    public function __construct(
        private readonly FieldTypeDetectorInterface $fieldTypeDetector,
    ) {
    }

    /**
     * @param string[] $knownLocales
     */
    public function resolve(
        PropertyInterface $property,
        NodeInterface $node,
        string $documentType,
        array $knownLocales,
    ): mixed {
        $value = $property instanceof Property
            ? $property->getValueForStorage()
            : $property->getValue();

        if (!\is_string($value) || '' === $value || '0' === $value) {
            return $value;
        }

        if ($this->shouldSkipDecode($property->getName(), $node, $documentType, $knownLocales)) {
            return $value;
        }

        $decoded = \json_decode($value, true);

        return \JSON_ERROR_NONE === \json_last_error() ? $decoded : $value;
    }

    /**
     * @param string[] $knownLocales
     */
    private function shouldSkipDecode(
        string $propertyName,
        NodeInterface $node,
        string $documentType,
        array $knownLocales,
    ): bool {
        $fieldType = $this->fieldTypeDetector->getType(
            $documentType,
            $propertyName,
            $node,
            $knownLocales,
        );

        return \in_array($fieldType, self::SKIP_DECODE_FIELD_TYPES, true);
    }
}
