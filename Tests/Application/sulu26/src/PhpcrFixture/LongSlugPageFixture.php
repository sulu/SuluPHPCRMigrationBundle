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
 * Creates a page whose URL is longer than the 144 characters of a legacy ro_routes.slug
 * column, and whose previous URL (a history route) is just as long. The migration must
 * accept both up to the width of the target column.
 */
class LongSlugPageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Long Slug Page');
        $page->setStructureType('default');
        $page->setResourceSegment('/' . \str_repeat('a', 159));
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Long Slug Page',
            'article' => '<p>Page with a URL of 160 characters.</p>',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'en');
        $page->setResourceSegment('/' . \str_repeat('b', 159));
        $this->documentManager->persist($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
