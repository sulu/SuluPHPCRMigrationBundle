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
use Sulu\Component\Content\Document\RedirectType;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;

class ShadowInternalLinkPageFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $targetPage */
        $targetPage = $this->documentManager->create('page');
        $targetPage->setLocale('de');
        $targetPage->setTitle('Linkziel-Seite');
        $targetPage->setStructureType('default');
        $targetPage->setResourceSegment('/linkziel-seite');
        $targetPage->setWorkflowStage(WorkflowStage::PUBLISHED);
        $targetPage->getStructure()->bind([
            'title' => 'Linkziel-Seite',
            'article' => '<p>Diese Seite ist das Ziel eines internen Links.</p>',
        ]);

        $this->documentManager->persist($targetPage, 'de', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($targetPage, 'de');
        $this->documentManager->flush();

        $targetUuid = $targetPage->getUuid();

        /** @var PageDocument $linkPage */
        $linkPage = $this->documentManager->create('page');
        $linkPage->setLocale('de');
        $linkPage->setTitle('Schatten-Interner-Link');
        $linkPage->setStructureType('default');
        $linkPage->setResourceSegment('/schatten-interner-link');
        $linkPage->setWorkflowStage(WorkflowStage::PUBLISHED);
        $linkPage->setRedirectType(RedirectType::INTERNAL);
        $linkPage->setRedirectTarget($this->documentManager->find($targetUuid, 'de'));
        $linkPage->getStructure()->bind([
            'title' => 'Schatten-Interner-Link',
        ]);

        $this->documentManager->persist($linkPage, 'de', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($linkPage, 'de');
        $this->documentManager->flush();

        $linkPageUuid = $linkPage->getUuid();
        $this->documentManager->flush();
        $this->documentManager->clear();

        /** @var PageDocument $linkPage */
        $linkPage = $this->documentManager->find($linkPageUuid, 'en', ['load_ghost_content' => false]);
        $linkPage->setTitle('Shadow Internal Link');
        $linkPage->setStructureType('default');
        $linkPage->setResourceSegment('/shadow-internal-link');
        $linkPage->setWorkflowStage(WorkflowStage::PUBLISHED);
        $linkPage->getStructure()->bind([
            'title' => 'Shadow Internal Link',
        ]);

        $this->documentManager->persist($linkPage, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($linkPage, 'en');
        $this->documentManager->flush();

        $linkPage->setShadowLocaleEnabled(true);
        $linkPage->setShadowLocale('de');

        $this->documentManager->persist($linkPage, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
