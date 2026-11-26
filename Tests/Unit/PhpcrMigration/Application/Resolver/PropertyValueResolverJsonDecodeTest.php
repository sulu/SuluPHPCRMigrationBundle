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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Unit\PhpcrMigration\Application\Resolver;

use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Detector\FieldTypeDetectorInterface;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Resolver\PropertyValueResolver;

#[CoversClass(PropertyValueResolver::class)]
final class PropertyValueResolverJsonDecodeTest extends TestCase
{
    use ProphecyTrait;

    #[DataProvider('provideQuotedStrings')]
    public function testKeepsTheQuotesOfAQuotedString(?string $fieldType, string $value): void
    {
        self::assertSame($value, $this->resolve($fieldType, $value));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function provideQuotedStrings(): iterable
    {
        yield 'excerpt title' => [null, '"Weniger ist meistens mehr"'];
        yield 'text editor' => ['text_editor', '"Zitat"'];
        yield 'escaped quote inside' => [null, '"Er sagte \"Hallo\""'];
    }

    /**
     * @param array<mixed>|int|float|bool|null $expected
     */
    #[DataProvider('provideDecodedValues')]
    public function testDecodesJsonValues(string $value, array|int|float|bool|null $expected): void
    {
        self::assertSame($expected, $this->resolve(null, $value));
    }

    /**
     * @return iterable<string, array{string, array<mixed>|int|float|bool|null}>
     */
    public static function provideDecodedValues(): iterable
    {
        yield 'object' => ['{"id":5,"displayOption":"top"}', ['id' => 5, 'displayOption' => 'top']];
        yield 'list' => ['["a","b"]', ['a', 'b']];
        yield 'number' => ['12', 12];
        yield 'boolean' => ['true', true];
    }

    public function testKeepsAPlainString(): void
    {
        self::assertSame('Weniger ist meistens mehr', $this->resolve(null, 'Weniger ist meistens mehr'));
    }

    private function resolve(?string $fieldType, string $value): mixed
    {
        $node = $this->prophesize(NodeInterface::class)->reveal();
        $detector = $this->prophesize(FieldTypeDetectorInterface::class);
        $detector->getType('page', 'i18n:de-excerpt-title', $node)->willReturn($fieldType);

        $property = $this->prophesize(PropertyInterface::class);
        $property->getName()->willReturn('i18n:de-excerpt-title');
        $property->getValue()->willReturn($value);

        return (new PropertyValueResolver($detector->reveal()))->resolve($property->reveal(), $node, 'page');
    }
}
