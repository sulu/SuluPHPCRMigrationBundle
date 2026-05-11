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
 * Creates a page that only exists in draft state (never published) to verify that
 * the draft unlocalized dimension gets availableLocales=["en"] instead of [].
 */
class DraftOnlyPageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Draft Only Page');
        $page->setStructureType('default');
        $page->setResourceSegment('/draft-only-page');
        $page->setWorkflowStage(WorkflowStage::TEST);
        $page->getStructure()->bind([
            'title' => 'Draft Only Page',
            'article' => '<p>This page was never published.</p>',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
