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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector\FormMetadataFieldTypeDetector;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Extractor\LocaleExtractor;

/**
 * Covers the options of fields inside block, nested block and global block types.
 */
#[CoversClass(FormMetadataFieldTypeDetector::class)]
final class FormMetadataFieldTypeDetectorBlockOptionTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideProperties(): iterable
    {
        yield 'top level' => ['i18n:de-mediaSmartContent', 'media'];
        yield 'block' => ['i18n:de-blocks-downloads#0', 'media'];
        yield 'block with pages provider' => ['i18n:de-blocks-pages#1', 'pages'];
        yield 'nested block' => ['i18n:de-blocks-innerBlocks#2-downloads#0', 'media'];
        yield 'nested block with pages provider' => ['i18n:de-blocks-innerBlocks#2-pages#1', 'pages'];
        yield 'global block' => ['i18n:de-blocks-downloads#3', 'media'];
        yield 'nested block in a global block' => ['i18n:de-blocks-innerBlocks#4-downloads#0', 'media'];
        yield 'global block in a global block' => ['i18n:de-blocks-globalBlocks#5-downloads#0', 'media'];
        yield 'text field in a global block' => ['i18n:de-blocks-title#3', null];
    }

    #[DataProvider('provideProperties')]
    public function testReturnsTheProviderOfSmartContents(string $propertyName, ?string $expectedProvider): void
    {
        $detector = $this->createDetector();
        $node = $this->createNode();

        self::assertSame($expectedProvider, $detector->getOption('page', $propertyName, $node, 'provider'));
    }

    public function testReturnsTheTypeOfFieldsInGlobalBlocks(): void
    {
        $detector = $this->createDetector();
        $node = $this->createNode();

        self::assertSame('smart_content', $detector->getType('page', 'i18n:de-blocks-downloads#3', $node));
        self::assertSame('text_line', $detector->getType('page', 'i18n:de-blocks-title#3', $node));
    }

    public function testIgnoresAGlobalBlockWhichDoesNotExist(): void
    {
        $detector = $this->createDetector();
        $node = $this->createNode();

        self::assertNull($detector->getOption('page', 'i18n:de-blocks-downloads#6', $node, 'provider'));
        self::assertNull($detector->getType('page', 'i18n:de-blocks-downloads#6', $node));
    }

    private function createDetector(): FormMetadataFieldTypeDetector
    {
        // Page template: a top level smart content and a block field with all kinds of block types
        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->createForm('download', [$this->createSmartContent('downloads', 'media')]));
        $blocks->addType($this->createForm('pages', [$this->createSmartContent('pages', 'pages')]));
        $blocks->addType($this->createForm('nested', [
            $this->createBlock('innerBlocks', [
                $this->createForm('inner_download', [$this->createSmartContent('downloads', 'media')]),
                $this->createForm('inner_pages', [$this->createSmartContent('pages', 'pages')]),
            ]),
        ]));
        $blocks->addType($this->createGlobalBlock('global_download'));
        $blocks->addType($this->createGlobalBlock('global_nested'));
        $blocks->addType($this->createGlobalBlock('global_in_global'));
        $blocks->addType($this->createGlobalBlock('global_missing'));

        $page = new FormMetadata();
        $page->setKey('default');
        $page->setItems([
            'mediaSmartContent' => $this->createSmartContent('mediaSmartContent', 'media'),
            'blocks' => $blocks,
        ]);

        $pages = new TypedFormMetadata();
        $pages->addForm('default', $page);

        // Global blocks: its types have no items in the template, the fields are declared in the "block" form
        $title = new FieldMetadata('title');
        $title->setType('text_line');

        $globalBlocks = new TypedFormMetadata();
        $globalBlocks->addForm('global_download', $this->createForm('global_download', [
            $title,
            $this->createSmartContent('downloads', 'media'),
        ]));
        $globalBlocks->addForm('global_nested', $this->createForm('global_nested', [
            $this->createBlock('innerBlocks', [
                $this->createForm('inner_download', [$this->createSmartContent('downloads', 'media')]),
            ]),
        ]));
        $globalBlocks->addForm('global_in_global', $this->createForm('global_in_global', [
            $this->createBlock('globalBlocks', [$this->createGlobalBlock('global_download')]),
        ]));

        $metadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $metadataProvider->getMetadata('page', Argument::any(), Argument::any())->willReturn($pages);
        $metadataProvider->getMetadata('block', Argument::any(), ['ignore_global_blocks' => true])->willReturn($globalBlocks);

        return new FormMetadataFieldTypeDetector(
            $metadataProvider->reveal(),
            new LocaleExtractor(),
            ['page' => ['default' => []]],
        );
    }

    /**
     * @param array<FieldMetadata> $items
     */
    private function createForm(string $key, array $items): FormMetadata
    {
        $form = new FormMetadata();
        $form->setKey($key);
        foreach ($items as $item) {
            $form->addItem($item);
        }

        return $form;
    }

    /**
     * @param array<FormMetadata> $types
     */
    private function createBlock(string $name, array $types): FieldMetadata
    {
        $block = new FieldMetadata($name);
        $block->setType('block');
        foreach ($types as $type) {
            $block->addType($type);
        }

        return $block;
    }

    private function createGlobalBlock(string $name): FormMetadata
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => $name]);

        $type = new FormMetadata();
        $type->setKey($name);
        $type->addTag($tag);

        return $type;
    }

    private function createSmartContent(string $name, string $provider): FieldMetadata
    {
        $option = new OptionMetadata();
        $option->setName('provider');
        $option->setValue($provider);

        $field = new FieldMetadata($name);
        $field->setType('smart_content');
        $field->addOption($option);

        return $field;
    }

    private function createNode(): NodeInterface
    {
        $properties = [];
        $values = [
            'i18n:de-title' => 'Page',
            'i18n:de-created' => '2026-11-04',
            'i18n:de-template' => 'default',
            'i18n:de-mediaSmartContent' => '{"types":[1]}',
            // the property name of the block types: "{block}-type#{index}" and "{block}-{innerBlock}#{index}-type#{innerIndex}"
            'i18n:de-blocks-type#0' => 'download',
            'i18n:de-blocks-type#1' => 'pages',
            'i18n:de-blocks-type#2' => 'nested',
            'i18n:de-blocks-innerBlocks#2-type#0' => 'inner_download',
            'i18n:de-blocks-innerBlocks#2-type#1' => 'inner_pages',
            'i18n:de-blocks-type#3' => 'global_download',
            'i18n:de-blocks-type#4' => 'global_nested',
            'i18n:de-blocks-innerBlocks#4-type#0' => 'inner_download',
            'i18n:de-blocks-type#5' => 'global_in_global',
            'i18n:de-blocks-globalBlocks#5-type#0' => 'global_download',
            'i18n:de-blocks-type#6' => 'global_missing',
        ];

        foreach ($values as $name => $value) {
            $property = $this->prophesize(PropertyInterface::class);
            $property->getName()->willReturn($name);
            $property->getValue()->willReturn($value);
            $properties[$name] = $property->reveal();
        }

        $node = $this->prophesize(NodeInterface::class);
        $node->getIdentifier()->willReturn('9f1d5a1c-0000-4000-8000-000000000001');
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
