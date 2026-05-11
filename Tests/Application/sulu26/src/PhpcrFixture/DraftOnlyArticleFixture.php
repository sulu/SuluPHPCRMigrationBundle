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

use Sulu\Bundle\ArticleBundle\Document\ArticleDocument;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;

/**
 * Creates an article that only exists in draft state (never published) to verify that
 * the draft unlocalized dimension gets availableLocales=["en"] instead of [].
 */
class DraftOnlyArticleFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var ArticleDocument $article */
        $article = $this->documentManager->create('article');
        $article->setLocale('en');
        $article->setTitle('Draft Only Article');
        $article->setStructureType('default');
        $article->setWorkflowStage(WorkflowStage::TEST);
        $article->setAuthor(1);
        $article->getStructure()->bind([
            'title' => 'Draft Only Article',
            'article' => '<p>This article was never published.</p>',
        ]);

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
