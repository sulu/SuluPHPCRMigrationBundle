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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor;

use PHPCR\NodeInterface;

class LocaleExtractor
{
    private const VALID_SUFFIXES = ['-title', '-template', '-created'];
    private const MIN_REQUIRED_MATCHES = 2;

    /**
     * @var array<string, string[]>
     */
    private array $localeCache = [];

    /**
     * @return string[]
     */
    public function extract(NodeInterface $node): array
    {
        $nodeIdentifier = $node->getIdentifier();
        if (isset($this->localeCache[$nodeIdentifier])) {
            return $this->localeCache[$nodeIdentifier];
        }

        $locales = $this->extractValidatedLocales($node);
        $this->localeCache[$nodeIdentifier] = $locales;

        return $locales;
    }

    /**
     * @return array<string, string>
     */
    private function extractValidatedLocales(NodeInterface $node): array
    {
        /** @var array<string, int> $localeCounts */
        $localeCounts = [];

        foreach (self::VALID_SUFFIXES as $suffix) {
            foreach ($this->extractLocalesBySuffix($node, $suffix) as $locale) {
                $localeCounts[$locale] = ($localeCounts[$locale] ?? 0) + 1;
            }
        }

        $locales = [];
        foreach ($localeCounts as $locale => $count) {
            if ($count >= self::MIN_REQUIRED_MATCHES) {
                $locales[$locale] = $locale;
            }
        }

        return $locales;
    }

    /**
     * @return array<string, string>
     */
    private function extractLocalesBySuffix(NodeInterface $node, string $suffix): array
    {
        $locales = [];
        foreach ($node->getProperties('i18n:*' . $suffix) as $property) {
            $afterPrefix = \substr($property->getName(), 5); // Remove 'i18n:'
            $locale = \substr($afterPrefix, 0, -\strlen($suffix));
            $locales[$locale] = $locale;
        }

        return $locales;
    }
}
