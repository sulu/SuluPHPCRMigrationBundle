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

use PHPCR\SessionInterface;
use Sulu\Bundle\PageBundle\Document\PageDocument;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;

/**
 * Creates a page whose sulu:creator and sulu:changer reference a user ID that does
 * not exist in se_users. The migration must null out these FK values instead of
 * failing with an integrity constraint violation.
 */
class OrphanCreatorChangerPageFixture implements PhpcrFixtureInterface
{
    private const ORPHAN_USER_ID = 9999;

    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        private readonly SessionInterface $defaultSession,
        private readonly SessionInterface $liveSession,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $page */
        $page = $this->documentManager->create('page');
        $page->setLocale('en');
        $page->setTitle('Orphan Creator Changer Page');
        $page->setStructureType('default');
        $page->setResourceSegment('/orphan-creator-changer-page');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Orphan Creator Changer Page',
            'article' => '<p>Page with deleted creator and changer.</p>',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        $this->overrideCreatorChanger($this->defaultSession, $uuid);
        $this->overrideCreatorChanger($this->liveSession, $uuid);
    }

    private function overrideCreatorChanger(SessionInterface $session, string $uuid): void
    {
        $node = $session->getNodeByIdentifier($uuid);
        $node->setProperty('sulu:creator', self::ORPHAN_USER_ID);
        $node->setProperty('sulu:changer', self::ORPHAN_USER_ID);
        $session->save();
    }
}
