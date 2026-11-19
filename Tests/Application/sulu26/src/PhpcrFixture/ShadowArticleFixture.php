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

class ShadowArticleFixture implements PhpcrFixtureInterface
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
        $article->setTitle('Schatten-Artikel');
        $article->setStructureType('default');
        $article->setWorkflowStage(WorkflowStage::PUBLISHED);
        $article->setAuthor(1);
        $article->getStructure()->bind([
            'title' => 'Schatten-Artikel',
            'article' => '<p>Deutscher Artikelinhalt für Schattentest.</p>',
        ]);

        $this->documentManager->persist($article, 'de');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'de');
        $this->documentManager->flush();

        $uuid = $article->getUuid();
        $this->documentManager->flush();
        $this->documentManager->clear();

        /** @var ArticleDocument $article */
        $article = $this->documentManager->find($uuid, 'en', ['load_ghost_content' => false]);
        $article->setTitle('Shadow Article');
        $article->setStructureType('default');
        $article->setWorkflowStage(WorkflowStage::PUBLISHED);
        $article->setAuthor(1);
        $article->getStructure()->bind([
            'title' => 'Shadow Article',
            'routePath' => '/blog/shadow-article-old',
        ]);

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();

        // Republishing under a new url leaves the old one as history route of the later shadow.
        $article->getStructure()->bind([
            'title' => 'Shadow Article',
            'routePath' => '/blog/shadow-article',
        ]);
        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();

        $article->setShadowLocaleEnabled(true);
        $article->setShadowLocale('de');

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        // Persisting a shadow loads the content of its source locale, publishing needs the shadow locale again.
        $article->setLocale('en');
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
