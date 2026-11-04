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
 * Covers the migration of the media type ids (1 = document, 2 = image, 3 = video, 4 = audio) of
 * smart contents with the "media" provider, both as top-level property and inside a block. The
 * "pages" smart content in the same block list keeps its page type names untouched.
 */
class MediaSmartContentPageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Media Smart Content');
        $page->setStructureType('default');
        $page->setResourceSegment('/media-smart-content');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Media Smart Content',
            'mediaSmartContent' => [
                'dataSource' => 1,
                'types' => [1, 2, 3, 4],
                'categories' => [1],
                'includeSubFolders' => true,
            ],
            'blocks' => [
                [
                    'type' => 'smart_content_media',
                    'title' => 'Media in a block',
                    'smartContentMedia' => [
                        'dataSource' => 1,
                        'types' => [2, 3],
                        'includeSubFolders' => false,
                    ],
                ],
                [
                    'type' => 'smart_content_pages',
                    'title' => 'Pages in a block',
                    'smartContent' => [
                        'dataSource' => 'db367f2b-7e1d-42e0-8ea2-b39b3d7511bf',
                        'types' => [2, 3],
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
