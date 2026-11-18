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
     *
     * @return string|null The field type (e.g. "text_editor", "text_line") or null if not found
     */
    public function getType(string $documentType, string $propertyName, NodeInterface $node): ?string;

    /**
     * Returns a scalar option (param) of the field of a given PHPCR property, e.g. the "provider" of a smart_content.
     *
     * @param string $name The option name as declared in the template (e.g. "provider")
     *
     * @return bool|int|string|null The option value or null if the field or the option is not found
     */
    public function getOption(string $documentType, string $propertyName, NodeInterface $node, string $name): bool|int|string|null;
}
