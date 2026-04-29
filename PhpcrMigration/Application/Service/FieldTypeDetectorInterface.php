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

interface FieldTypeDetectorInterface
{
    /**
     * Returns the field type for a given PHPCR property.
     *
     * @param string $type The content type (e.g. "page", "article", "snippet")
     * @param string $propertyName The PHPCR property name (e.g. "i18n:en-title", "i18n:en-blocks-code#0")
     * @param NodeInterface $node The PHPCR node
     * @param string $locale The locale to use for looking up block types
     *
     * @return string|null The field type (e.g. "text_editor", "text_line") or null if not found
     */
    public function getType(string $type, string $propertyName, NodeInterface $node, string $locale): ?string;
}
