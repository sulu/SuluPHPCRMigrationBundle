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

interface FieldTypeDetectorInterface
{
    /**
     * Returns the field type for a given PHPCR property.
     *
     * @param string $documentType The content type (e.g. "page", "article", "snippet")
     * @param string $propertyName The PHPCR property name (e.g. "i18n:en-title", "i18n:en-blocks-code#0")
     * @param string[] $knownLocales Locales discovered for the node; the detector picks one to look up the template
     *
     * @return string|null The field type (e.g. "text_editor", "text_line") or null if not found
     */
    public function getType(
        string $documentType,
        string $propertyName,
        NodeInterface $node,
        array $knownLocales,
    ): ?string;
}
