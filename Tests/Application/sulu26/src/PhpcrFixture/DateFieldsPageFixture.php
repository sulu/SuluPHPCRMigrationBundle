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
 * Page with `date` and `datetime` fields, stored by Sulu 2.6 as PHPCR \DateTime values,
 * to verify the migration converts them to the strings Sulu 3's resolvers expect (not null).
 */
class DateFieldsPageFixture implements PhpcrFixtureInterface
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
        $page->setTitle('Date Fields Page');
        $page->setStructureType('date-fields');
        $page->setResourceSegment('/date-fields-page');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Date Fields Page',
            'dateField' => '2024-01-15',
            'datetimeField' => '2024-01-15T13:45:30',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'de', ['load_ghost_content' => false]);
        $page->setTitle('Datumsfelder Seite');
        $page->setStructureType('date-fields');
        $page->setResourceSegment('/datumsfelder-seite');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Datumsfelder Seite',
            'dateField' => '2023-12-24',
            'datetimeField' => '2023-12-24T18:00:00',
        ]);

        $this->documentManager->persist($page, 'de');
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'de');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
