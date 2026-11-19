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
 * A published shadow page with a history route: English is published under an old url, republished
 * under a new one and then becomes a shadow of German. Sulu 2.6 redirects the old url to the route
 * of the shadow, so the migration must keep it.
 */
class ShadowHistoryRoutePageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Schatten-Verlauf');
        $page->setStructureType('default');
        $page->setResourceSegment('/schatten-verlauf');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Schatten-Verlauf',
        ]);

        $this->documentManager->persist($page, 'de', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'de');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'en', ['load_ghost_content' => false]);
        $page->setTitle('Shadow History');
        $page->setStructureType('default');
        $page->setResourceSegment('/shadow-history-old');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Shadow History',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $page->setResourceSegment('/shadow-history');

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();

        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'en', ['load_ghost_content' => false]);
        $page->setShadowLocaleEnabled(true);
        $page->setShadowLocale('de');

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
