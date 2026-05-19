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

namespace App\PhpcrFixture;

use Sulu\Bundle\PageBundle\Document\PageDocument;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;

/**
 * Reproduces a bug where nested block child properties whose names contain a
 * hyphen (e.g. `background-image`) are mis-parsed by `parseBlockPropertyPath()`
 * in `PropertyNodeParser`. The migrated `templateData` ends up nesting the
 * hyphen segments as separate path elements (`background` / `image`) instead
 * of keeping `background-image` as a single property name.
 */
class HyphenatedPropertyNestedBlockFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $page */
        $page = $this->documentManager->create('page');
        $page->setLocale('en');
        $page->setTitle('Hyphenated Property Nested Block');
        $page->setStructureType('default');
        $page->setResourceSegment('/hyphenated-property-nested-block');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Hyphenated Property Nested Block',
            'blocks' => [
                [
                    'type' => 'nested',
                    'title' => 'Outer Nested Block',
                    'innerBlocks' => [
                        [
                            'type' => 'inner_image',
                            'image' => ['id' => 5],
                            'caption' => 'First inner image',
                            'background-image' => ['id' => 4],
                        ],
                        [
                            'type' => 'inner_image',
                            'image' => ['id' => 3],
                            'caption' => 'Second inner image',
                            'background-image' => ['id' => 2],
                        ],
                    ],
                ],
            ],
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
