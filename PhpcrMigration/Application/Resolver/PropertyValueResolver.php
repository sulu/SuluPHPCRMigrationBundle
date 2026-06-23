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

    /**
     * Formats expected by Sulu 3's Date/DateTimePropertyResolver, which return null for anything else.
     *
     * @var array<string, string>
     */
    private const DATE_FIELD_FORMATS = [
        'date' => 'Y-m-d',
        'datetime' => 'Y-m-d\TH:i:s',
    ];

    public function __construct(
        private readonly FieldTypeDetectorInterface $fieldTypeDetector,
    ) {
    }

    public function resolve(PropertyInterface $property, NodeInterface $node, string $documentType): mixed
    {
        $value = $property instanceof Property
            ? $property->getValueForStorage()
            : $property->getValue();

        $fieldType = $this->fieldTypeDetector->getType($documentType, $property->getName(), $node);

        if (null !== $fieldType && isset(self::DATE_FIELD_FORMATS[$fieldType])) {
            return $this->formatDate($value, self::DATE_FIELD_FORMATS[$fieldType]);
        }

        if (!\is_string($value) || '' === $value || '0' === $value) {
            return $value;
        }

        if (\in_array($fieldType, self::SKIP_DECODE_FIELD_TYPES, true)) {
            return $value;
        }

        $decoded = \json_decode($value, true);

        return \JSON_ERROR_NONE === \json_last_error() ? $decoded : $value;
    }

    private function formatDate(mixed $value, string $format): mixed
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_string($value) && \str_starts_with($value, '{')) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (\is_array($value) && \is_string($value['date'] ?? null)) {
            $value = $value['date'];
        }

        if (\is_string($value)) {
            $parsed = \date_create_immutable($value);
            if (false === $parsed) {
                return $value;
            }
            $value = $parsed;
        }

        return $value instanceof \DateTimeInterface ? $value->format($format) : $value;
    }
}
