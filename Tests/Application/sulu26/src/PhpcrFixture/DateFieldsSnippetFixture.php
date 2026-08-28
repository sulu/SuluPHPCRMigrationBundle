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

use Sulu\Bundle\SnippetBundle\Document\SnippetDocument;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;

/**
 * Snippet with `date` and `datetime` fields. Unlike pages, snippets keep their template key
 * on an unlocalized `template` property; without reading it no field type is known and the
 * dates are migrated in their raw PHPCR shape instead of the strings Sulu 3 expects.
 */
class DateFieldsSnippetFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var SnippetDocument $snippet */
        $snippet = $this->documentManager->create('snippet');
        $snippet->setLocale('en');
        $snippet->setTitle('Date Fields Snippet');
        $snippet->setStructureType('date-fields');
        $snippet->setWorkflowStage(WorkflowStage::PUBLISHED);
        $snippet->getStructure()->bind([
            'title' => 'Date Fields Snippet',
            'dateField' => '2027-02-02',
            'datetimeField' => '2027-02-02T21:49:24',
        ]);

        $this->documentManager->persist($snippet, 'en', [
            'parent_path' => '/cmf/snippets/date-fields',
            'auto_create' => true,
        ]);
        $this->documentManager->flush();
        $this->documentManager->publish($snippet, 'en');
        $this->documentManager->flush();

        $uuid = $snippet->getUuid();
        $this->documentManager->clear();

        /** @var SnippetDocument $snippet */
        $snippet = $this->documentManager->find($uuid, 'de', ['load_ghost_content' => false]);
        $snippet->setTitle('Datumsfelder Snippet');
        $snippet->setStructureType('date-fields');
        $snippet->setWorkflowStage(WorkflowStage::PUBLISHED);
        $snippet->getStructure()->bind([
            'title' => 'Datumsfelder Snippet',
            'dateField' => '2026-12-24',
            'datetimeField' => '2026-12-24T18:00:00',
        ]);

        $this->documentManager->persist($snippet, 'de');
        $this->documentManager->flush();
        $this->documentManager->publish($snippet, 'de');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
