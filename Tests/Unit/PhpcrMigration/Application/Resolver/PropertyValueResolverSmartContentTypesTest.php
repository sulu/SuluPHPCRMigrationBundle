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
final class PropertyValueResolverSmartContentTypesTest extends TestCase
{
    use ProphecyTrait;

    public function testMapsTheMediaTypeIdsToTheTypeNames(): void
    {
        $resolved = $this->resolve('smart_content', '{"dataSource":21,"types":[1,2,3,4],"categories":[1650,23]}');

        self::assertSame(['dataSource' => 21, 'types' => ['document', 'image', 'video', 'audio'], 'categories' => [1650, 23]], $resolved);
    }

    public function testMapsASingleTypeId(): void
    {
        $resolved = $this->resolve('smart_content', '{"types":[1]}');

        self::assertSame(['types' => ['document']], $resolved);
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideUntouchedValues')]
    public function testLeavesOtherSmartContentTypesUntouched(string $json, array $expected): void
    {
        self::assertSame($expected, $this->resolve('smart_content', $json));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideUntouchedValues(): iterable
    {
        yield 'article types are names already' => ['{"types":["webinar","news"]}', ['types' => ['webinar', 'news']]];
        yield 'null types' => ['{"types":null,"sortBy":"title"}', ['types' => null, 'sortBy' => 'title']];
        yield 'unknown id stays' => ['{"types":[9]}', ['types' => [9]]];
        yield 'no types' => ['{"dataSource":"abc"}', ['dataSource' => 'abc']];
    }

    public function testOnlyTouchesSmartContentFields(): void
    {
        self::assertSame('{"types":[1,2]}', $this->resolve('text_area', '{"types":[1,2]}'));
        self::assertSame(['types' => [1, 2]], $this->resolve(null, '{"types":[1,2]}'));
    }

    private function resolve(?string $fieldType, string $json): mixed
    {
        $node = $this->prophesize(NodeInterface::class)->reveal();
        $detector = $this->prophesize(FieldTypeDetectorInterface::class);
        $detector->getType('article', 'i18n:de-downloads', $node)->willReturn($fieldType);

        $property = $this->prophesize(PropertyInterface::class);
        $property->getName()->willReturn('i18n:de-downloads');
        $property->getValue()->willReturn($json);

        return (new PropertyValueResolver($detector->reveal()))->resolve($property->reveal(), $node, 'article');
    }
}
