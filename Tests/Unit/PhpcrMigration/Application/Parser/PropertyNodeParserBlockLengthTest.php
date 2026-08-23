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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Parser;

use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector\FieldTypeDetectorInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor\LocaleExtractor;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser\PropertyNodeParser;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Resolver\PropertyValueResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(PropertyNodeParser::class)]
final class PropertyNodeParserBlockLengthTest extends TestCase
{
    use ProphecyTrait;

    public function testKeepsBlocksWhenTemplatePropertyIsNamedLength(): void
    {
        $properties = [
            'i18n:de-title' => 'Konzerte',
            'i18n:de-template' => 'composition',
            'i18n:de-music-length' => 2,
            'i18n:de-music-title#0' => 'Erster Satz',
            'i18n:de-music-length#0' => '0:45',
            'i18n:de-music-title#1' => 'Zweiter Satz',
            'i18n:de-music-length#1' => '1:12',
        ];

        $music = $this->localizedProperty($this->parse($properties), 'music');

        self::assertCount(2, $music, 'A block property named "length" is not the counter and must not trim.');
        self::assertSame(['title' => 'Erster Satz', 'length' => '0:45'], $music[0]);
        self::assertSame(['title' => 'Zweiter Satz', 'length' => '1:12'], $music[1]);
    }

    public function testStillTrimsBlocksToTheCounter(): void
    {
        $properties = [
            'i18n:de-title' => 'Konzerte',
            'i18n:de-template' => 'composition',
            'i18n:de-blocks-length' => 1,
            'i18n:de-blocks-title#0' => 'Bleibt',
            'i18n:de-blocks-title#1' => 'Wurde geloescht',
        ];

        $blocks = $this->localizedProperty($this->parse($properties), 'blocks');

        self::assertCount(1, $blocks, 'PHPCR leaves deleted blocks behind; only the first "length" entries belong to the document.');
        self::assertSame(['title' => 'Bleibt'], $blocks[0]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<int, array<string, mixed>>
     */
    private function localizedProperty(array $document, string $name): array
    {
        $localizations = $document['localizations'] ?? null;
        self::assertIsArray($localizations);

        $german = $localizations['de'] ?? null;
        self::assertIsArray($german);

        $value = $german[$name] ?? null;
        self::assertIsArray($value);

        /** @var array<int, array<string, mixed>> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function parse(array $properties): array
    {
        $propertyObjects = [];
        foreach ($properties as $name => $value) {
            $property = $this->prophesize(PropertyInterface::class);
            $property->getName()->willReturn($name);
            $property->getValue()->willReturn($value);
            $propertyObjects[$name] = $property->reveal();
        }

        $node = $this->prophesize(NodeInterface::class);
        $node->getIdentifier()->willReturn('4c0b1e5a-0000-4000-8000-000000000000');
        $node->getProperties()->willReturn($propertyObjects);

        // The locale is derived from the `-title`/`-template`/`-created` properties.
        $node->getProperties(Argument::type('string'))->will(
            function(array $args) use ($propertyObjects) {
                $suffix = \substr((string) $args[0], \strlen('i18n:*'));

                return \array_filter(
                    $propertyObjects,
                    static fn (string $name) => \str_ends_with($name, $suffix),
                    \ARRAY_FILTER_USE_KEY,
                );
            }
        );

        $detector = $this->prophesize(FieldTypeDetectorInterface::class);
        $detector->getType(Argument::cetera())->willReturn(null);

        $parser = new PropertyNodeParser(
            PropertyAccess::createPropertyAccessor(),
            new LocaleExtractor(),
            new PropertyValueResolver($detector->reveal()),
        );

        return $parser->parse($node->reveal(), 'page');
    }
}
