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
 * A "pure" shadow page: English becomes a shadow of German without ever being published with its
 * own resource locator. Unlike ShadowInternalLinkPageFixture, the shadow locale has no route of its
 * own, so the migration must mint one from the source slug and link the shadow's dimension content.
 */
class ShadowPurePageFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $page */
        $page = $this->documentManager->create('page');
        $page->setLocale('de');
        $page->setTitle('Schatten-Quelle');
        $page->setStructureType('default');
        $page->setResourceSegment('/schatten-quelle');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Schatten-Quelle',
        ]);

        $this->documentManager->persist($page, 'de', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'de');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        // English becomes a shadow with a title (required for the node name) but no resource
        // segment, so it has no route of its own.
        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'en', ['load_ghost_content' => false]);
        $page->setTitle('Shadow Source');
        $page->setShadowLocaleEnabled(true);
        $page->setShadowLocale('de');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
