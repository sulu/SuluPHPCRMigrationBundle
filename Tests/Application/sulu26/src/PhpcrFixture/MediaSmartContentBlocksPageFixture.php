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
 * Covers the migration of the media type ids of smart contents with the "media" provider inside a
 * nested block and inside a global block (`<type ref="global_media"/>`, declared in
 * config/templates/blocks/global_media.xml).
 */
class MediaSmartContentBlocksPageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Media Smart Content Blocks');
        $page->setStructureType('default');
        $page->setResourceSegment('/media-smart-content-blocks');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Media Smart Content Blocks',
            'blocks' => [
                [
                    'type' => 'nested',
                    'title' => 'Media in a nested block',
                    'innerBlocks' => [
                        [
                            'type' => 'inner_media',
                            'innerSmartContentMedia' => [
                                'dataSource' => 1,
                                'types' => [1, 4],
                                'includeSubFolders' => true,
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'global_media',
                    'title' => 'Media in a global block',
                    'globalSmartContentMedia' => [
                        'dataSource' => 1,
                        'types' => [2, 3],
                        'includeSubFolders' => false,
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
