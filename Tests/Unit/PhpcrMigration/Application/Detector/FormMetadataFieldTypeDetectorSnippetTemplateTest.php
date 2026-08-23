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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Detector;

use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector\FormMetadataFieldTypeDetector;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor\LocaleExtractor;

#[CoversClass(FormMetadataFieldTypeDetector::class)]
final class FormMetadataFieldTypeDetectorSnippetTemplateTest extends TestCase
{
    use ProphecyTrait;

    public function testDetectsFieldTypeForSnippetWithUnlocalizedTemplateProperty(): void
    {
        $date = new FieldMetadata('date');
        $date->setType('date');

        $form = new FormMetadata();
        $form->setKey('performance');
        $form->setItems(['date' => $date]);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('performance', $form);

        $metadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $metadataProvider->getMetadata('snippet', Argument::any(), Argument::any())->willReturn($typedForm);

        $detector = new FormMetadataFieldTypeDetector(
            $metadataProvider->reveal(),
            new LocaleExtractor(),
            ['snippet' => ['performance' => []]],
        );

        self::assertSame(
            'date',
            $detector->getType('snippet', 'i18n:de-date', $this->createSnippetNode()),
            'Snippets keep a single unlocalized "template" property; without reading it no field type is known.',
        );
    }

    private function createSnippetNode(): NodeInterface
    {
        $properties = [];
        $values = [
            'i18n:de-title' => 'Konzert',
            'i18n:de-created' => '2026-08-23',
            'i18n:de-date' => '2027-02-02',
            'template' => 'performance',
        ];

        foreach ($values as $name => $value) {
            $property = $this->prophesize(PropertyInterface::class);
            $property->getName()->willReturn($name);
            $property->getValue()->willReturn($value);
            $properties[$name] = $property->reveal();
        }

        $node = $this->prophesize(NodeInterface::class);
        $node->getIdentifier()->willReturn('9f1d5a1c-0000-4000-8000-000000000000');
        $node->getProperties()->willReturn($properties);
        $node->getProperties(Argument::type('string'))->will(
            function(array $args) use ($properties) {
                $suffix = \substr((string) $args[0], \strlen('i18n:*'));

                return \array_filter(
                    $properties,
                    static fn (string $name) => \str_ends_with($name, $suffix),
                    \ARRAY_FILTER_USE_KEY,
                );
            }
        );
        $node->hasProperty(Argument::any())->will(
            fn (array $args) => \array_key_exists($args[0], $properties)
        );
        $node->getPropertyValue(Argument::any())->will(
            fn (array $args) => $properties[$args[0]]->getValue()
        );

        return $node->reveal();
    }
}
