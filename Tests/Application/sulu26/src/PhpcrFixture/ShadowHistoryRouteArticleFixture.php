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
 * A published shadow article with a history route: English is published under an old url,
 * republished under a new one and then becomes a shadow of German. Sulu 2.6 redirects the old url
 * to the route of the shadow, so the migration must keep it.
 */
class ShadowHistoryRouteArticleFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var ArticleDocument $article */
        $article = $this->documentManager->create('article');
        $article->setLocale('de');
        $article->setTitle('Schatten-Verlauf-Artikel');
        $article->setStructureType('default');
        $article->setWorkflowStage(WorkflowStage::PUBLISHED);
        $article->setAuthor(1);
        $article->getStructure()->bind([
            'title' => 'Schatten-Verlauf-Artikel',
        ]);

        $this->documentManager->persist($article, 'de');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'de');
        $this->documentManager->flush();

        $uuid = $article->getUuid();
        $this->documentManager->clear();

        /** @var ArticleDocument $article */
        $article = $this->documentManager->find($uuid, 'en', ['load_ghost_content' => false]);
        $article->setTitle('Shadow History Article');
        $article->setStructureType('default');
        $article->setWorkflowStage(WorkflowStage::PUBLISHED);
        $article->setAuthor(1);
        $article->getStructure()->bind([
            'title' => 'Shadow History Article',
            'routePath' => '/blog/shadow-history-article-old',
        ]);

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();

        $article->getStructure()->bind([
            'title' => 'Shadow History Article',
            'routePath' => '/blog/shadow-history-article',
        ]);

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();

        /** @var ArticleDocument $article */
        $article = $this->documentManager->find($uuid, 'en', ['load_ghost_content' => false]);
        $article->setShadowLocaleEnabled(true);
        $article->setShadowLocale('de');

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        // Persisting a shadow switches the document to its source locale, publish validates that locale.
        $article->setLocale('en');
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
