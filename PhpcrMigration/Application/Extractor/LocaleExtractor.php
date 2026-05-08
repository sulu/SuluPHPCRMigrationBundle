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
    public const I18N_PREFIX = 'i18n:';

    private const VALID_SUFFIXES = ['-title', '-template', '-created'];
    private const MIN_REQUIRED_MATCHES = 2;

    /**
     * @var array<string, array<string, string>>
     */
    private array $localeCache = [];

    /**
     * Extracts the locales present on a node.
     *
     * The returned array is sorted longest-locale-first so that callers iterating
     * for prefix matches handle nested locales (e.g. "de-ch" vs "de") correctly
     * without needing to sort themselves.
     *
     * @return array<string, string>
     */
    public function extract(NodeInterface $node): array
    {
        $nodeIdentifier = $node->getIdentifier();
        if (isset($this->localeCache[$nodeIdentifier])) {
            return $this->localeCache[$nodeIdentifier];
        }

        $locales = $this->extractValidatedLocales($node);
        \uksort($locales, fn ($a, $b) => \strlen($b) - \strlen($a));
        $this->localeCache[$nodeIdentifier] = $locales;

        return $locales;
    }

    /**
     * Returns the locale that matches the `i18n:{locale}-` prefix on the given property name.
     * Returns null if the name is not i18n-prefixed or no known locale on the node matches.
     *
     * Relies on {@see extract()} returning locales longest-first so that nested locales
     * like "de-ch" are matched before "de".
     */
    public function matchLocale(string $propertyName, NodeInterface $node): ?string
    {
        if (!\str_starts_with($propertyName, self::I18N_PREFIX)) {
            return null;
        }

        $afterPrefix = \substr($propertyName, \strlen(self::I18N_PREFIX));

        foreach ($this->extract($node) as $locale) {
            if (\str_starts_with($afterPrefix, $locale . '-')) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Returns any one of the locales present on the node, or null if none.
     *
     * Useful when the caller needs an arbitrary locale to look up locale-independent
     * structures (e.g. template metadata) for an unlocalized property.
     */
    public function firstLocale(NodeInterface $node): ?string
    {
        foreach ($this->extract($node) as $locale) {
            return $locale;
        }

        return null;
    }

    /**
     * Strips the `i18n:{locale}-` prefix from a property name.
     * E.g., "i18n:en-blocks-code#0" with locale "en" becomes "blocks-code#0".
     */
    public function stripPrefix(string $propertyName, string $locale): string
    {
        $prefix = self::I18N_PREFIX . $locale . '-';
        if (\str_starts_with($propertyName, $prefix)) {
            return \substr($propertyName, \strlen($prefix));
        }

        return $propertyName;
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
        foreach ($node->getProperties(self::I18N_PREFIX . '*' . $suffix) as $property) {
            $afterPrefix = \substr($property->getName(), \strlen(self::I18N_PREFIX));
            $locale = \substr($afterPrefix, 0, -\strlen($suffix));
            $locales[$locale] = $locale;
        }

        return $locales;
    }
}
