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
 * A published page moved away from its URL, which leaves a history route behind, and an unpublished
 * page that has the same URL as its draft. The migration has to give the history route the slug and
 * the draft another one.
 */
class HistoryRouteSlugCollisionPageFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $moved */
        $moved = $this->documentManager->create('page');
        $moved->setLocale('en');
        $moved->setTitle('History Collision Moved');
        $moved->setStructureType('default');
        $moved->setResourceSegment('/history-collision');
        $moved->setWorkflowStage(WorkflowStage::PUBLISHED);
        $moved->getStructure()->bind([
            'title' => 'History Collision Moved',
        ]);

        $this->documentManager->persist($moved, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($moved, 'en');
        $this->documentManager->flush();

        $uuid = $moved->getUuid();
        $this->documentManager->clear();

        /** @var PageDocument $moved */
        $moved = $this->documentManager->find($uuid, 'en');
        $moved->setResourceSegment('/history-collision-moved');
        $this->documentManager->persist($moved, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($moved, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();

        /** @var PageDocument $draft */
        $draft = $this->documentManager->create('page');
        $draft->setLocale('en');
        $draft->setTitle('History Collision Draft');
        $draft->setStructureType('default');
        $draft->setResourceSegment('/history-collision');
        $draft->setWorkflowStage(WorkflowStage::TEST);
        $draft->getStructure()->bind([
            'title' => 'History Collision Draft',
        ]);

        $this->documentManager->persist($draft, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
