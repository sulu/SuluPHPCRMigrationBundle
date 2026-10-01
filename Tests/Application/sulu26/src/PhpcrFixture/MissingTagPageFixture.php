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
 * Creates a page whose excerpt tags reference an existing tag and a tag ID that does not
 * exist in ta_tags. The migration must skip the missing tag instead of failing on the
 * foreign key, which on PostgreSQL would abort the whole transaction.
 */
class MissingTagPageFixture implements PhpcrFixtureInterface
{
    private const EXISTING_TAG_ID = 3;

    private const MISSING_TAG_ID = 9999;

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
        $page->setTitle('Missing Tag Page');
        $page->setStructureType('default');
        $page->setResourceSegment('/missing-tag-page');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Missing Tag Page',
            'article' => '<p>Page with a deleted tag in its excerpt.</p>',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        $this->overrideExcerptTags($this->defaultSession, $uuid);
        $this->overrideExcerptTags($this->liveSession, $uuid);
    }

    private function overrideExcerptTags(SessionInterface $session, string $uuid): void
    {
        $node = $session->getNodeByIdentifier($uuid);
        $node->setProperty('i18n:en-excerpt-tags', [self::EXISTING_TAG_ID, self::MISSING_TAG_ID]);
        $session->save();
    }
}
