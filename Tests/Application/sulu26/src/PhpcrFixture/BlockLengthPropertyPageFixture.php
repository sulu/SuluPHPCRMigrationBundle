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
 * Page with a block whose own properties include one named `length`. PHPCR stores it as
 * `tracks-length#<index>` next to the block counter `tracks-length`; the migration must keep
 * the block property and only trim by the counter.
 */
class BlockLengthPropertyPageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Track List Page');
        $page->setStructureType('track-list');
        $page->setResourceSegment('/track-list-page');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Track List Page',
            'tracks' => [
                ['type' => 'track', 'title' => 'First Movement', 'length' => '0:45'],
                ['type' => 'track', 'title' => 'Second Movement', 'length' => '1:12'],
            ],
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
