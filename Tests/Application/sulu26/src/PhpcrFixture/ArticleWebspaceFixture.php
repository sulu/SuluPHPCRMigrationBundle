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
 * Creates an article with explicit webspace settings (mainWebspace: website_2, additionalWebspaces: [website]).
 * This tests the migration path where PHPCR articles have non-default webspace combinations.
 */
class ArticleWebspaceFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        $locale = 'de';

        /** @var ArticleDocument $article */
        $article = $this->documentManager->create('article');
        $article->setLocale($locale);
        $article->setTitle('Article with custom webspaces');
        $article->setStructureType('default');
        $article->setWorkflowStage(WorkflowStage::PUBLISHED);
        $article->setAuthor(1);
        $article->setMainWebspace('website_2');
        $article->setAdditionalWebspaces(['website']);
        $article->getStructure()->bind([
            'title' => 'Article with custom webspaces',
            'article' => '<p>This article has mainWebspace=website_2 and additionalWebspaces=[website].</p>',
        ]);

        $this->documentManager->persist($article, $locale);
        $this->documentManager->flush();
        $this->documentManager->publish($article, $locale);
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
