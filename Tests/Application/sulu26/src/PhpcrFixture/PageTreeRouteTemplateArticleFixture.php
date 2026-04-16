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

class PageTreeRouteTemplateArticleFixture implements PhpcrFixtureInterface
{
    private const PARENT_PAGE_UUID = '16060395-4bbf-4e1d-9c8e-fe5265890c99';
    private const PARENT_PAGE_PATH = '/example-page-1';

    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
    ) {
    }

    public function load(): void
    {
        /** @var ArticleDocument $article */
        $article = $this->documentManager->create('article');
        $article->setLocale('en');
        $article->setTitle('Page Tree Route Article');
        $article->setStructureType('page_tree_route');
        $article->setWorkflowStage(WorkflowStage::PUBLISHED);
        $article->setAuthor(1);
        $article->getStructure()->bind([
            'title' => 'Page Tree Route Article',
            'routePath' => [
                'page' => [
                    'uuid' => self::PARENT_PAGE_UUID,
                    'path' => self::PARENT_PAGE_PATH,
                ],
                'suffix' => '/page-tree-route-article',
            ],
            'article' => '<p>This article uses page_tree_route routing under a parent page.</p>',
        ]);

        $this->documentManager->persist($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->publish($article, 'en');
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
