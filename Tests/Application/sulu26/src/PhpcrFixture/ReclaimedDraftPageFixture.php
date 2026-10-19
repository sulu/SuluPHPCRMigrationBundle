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
 * Two pages with the same resource segment, the draft one created first so the migration
 * persists its route before the published page reclaims the slug.
 */
class ReclaimedDraftPageFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $draft */
        $draft = $this->documentManager->create('page');
        $draft->setLocale('en');
        $draft->setTitle('Reclaimed Draft');
        $draft->setStructureType('default');
        $draft->setResourceSegment('/reclaimed');
        $draft->setWorkflowStage(WorkflowStage::TEST);
        $draft->getStructure()->bind([
            'title' => 'Reclaimed Draft',
        ]);

        $this->documentManager->persist($draft, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->clear();

        /** @var PageDocument $published */
        $published = $this->documentManager->create('page');
        $published->setLocale('en');
        $published->setTitle('Reclaimed Published');
        $published->setStructureType('default');
        $published->setResourceSegment('/reclaimed');
        $published->setWorkflowStage(WorkflowStage::PUBLISHED);
        $published->getStructure()->bind([
            'title' => 'Reclaimed Published',
        ]);

        $this->documentManager->persist($published, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($published, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
