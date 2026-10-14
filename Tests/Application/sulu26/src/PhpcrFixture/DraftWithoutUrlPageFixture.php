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
 * A draft page without a stored url, so the migration has no slug to build its route from.
 * The document manager rejects a page without its mandatory url, so the property is removed on the node.
 */
class DraftWithoutUrlPageFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        private readonly SessionInterface $defaultSession,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $page */
        $page = $this->documentManager->create('page');
        $page->setLocale('en');
        $page->setTitle('Draft Without Url');
        $page->setStructureType('default');
        $page->setResourceSegment('/draft-without-url');
        $page->setWorkflowStage(WorkflowStage::TEST);
        $page->getStructure()->bind([
            'title' => 'Draft Without Url',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        $node = $this->defaultSession->getNodeByIdentifier($uuid);
        $node->getProperty('i18n:en-url')->remove();
        $this->defaultSession->save();
    }
}
