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
use Sulu\Component\Content\Document\RedirectType;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Link pages keep a stale url without a route node, while the route node of that url belongs to the
 * link target: its route in en and its history route in de. The target has to keep both and the
 * link page gets a generated slug, whether the link page is migrated before the target (its parent)
 * or after it (a later sibling).
 */
class StaleLinkUrlPageFixture implements PhpcrFixtureInterface
{
    /**
     * The sessions of the document manager, so it does not keep the removed route nodes in its cache.
     */
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        #[Autowire(service: 'sulu_document_manager.default_session')]
        private readonly SessionInterface $documentDefaultSession,
        #[Autowire(service: 'sulu_document_manager.live_session')]
        private readonly SessionInterface $documentLiveSession,
    ) {
    }

    public function load(): void
    {
        $this->createLinkWithStaleUrls('stale-link-first', 'Stale Link First', 'Veralteter Link Zuerst', true);
        $this->createLinkWithStaleUrls('stale-link-last', 'Stale Link Last', 'Veralteter Link Zuletzt', false);
    }

    private function createLinkWithStaleUrls(string $prefix, string $titleEn, string $titleDe, bool $linkIsParent): void
    {
        $targetUrls = ['en' => '/' . $prefix . '-target', 'de' => '/' . $prefix . '-ziel-alt'];
        $targetTitles = ['en' => $titleEn . ' Target', 'de' => $titleDe . ' Ziel'];
        $staleUrls = ['en' => '/' . $prefix . '-main', 'de' => '/' . $prefix . '-history'];

        $targetUuid = $linkIsParent
            ? null
            : $this->createPublishedPage($targetTitles, $targetUrls, '/cmf/website/contents');
        $linkUuid = $this->createPublishedPage(['en' => $titleEn, 'de' => $titleDe], $staleUrls, '/cmf/website/contents');
        if (null === $targetUuid) {
            /** @var PageDocument $link */
            $link = $this->documentManager->find($linkUuid, 'en');
            $linkPath = $link->getPath();
            $this->documentManager->clear();

            $targetUuid = $this->createPublishedPage($targetTitles, $targetUrls, $linkPath);
        }

        foreach (['en', 'de'] as $locale) {
            /** @var PageDocument $link */
            $link = $this->documentManager->find($linkUuid, $locale);
            $link->setRedirectType(RedirectType::INTERNAL);
            $link->setRedirectTarget($this->documentManager->find($targetUuid, $locale));
            $this->documentManager->persist($link, $locale);
            $this->documentManager->flush();
            $this->documentManager->publish($link, $locale);
            $this->documentManager->flush();
            $this->documentManager->clear();
        }

        // Sulu 2.6 does not update the url of a link page, so its route node can end up at another page.
        foreach ([$this->documentDefaultSession, $this->documentLiveSession] as $session) {
            foreach ($staleUrls as $locale => $url) {
                $routePath = '/cmf/website/routes/' . $locale . $url;
                if ($session->nodeExists($routePath)) {
                    $session->getNode($routePath)->remove();
                }
            }
            $session->save();
        }

        $this->publishUrl($targetUuid, 'en', $staleUrls['en']);
        $this->publishUrl($targetUuid, 'de', $staleUrls['de']);
        $this->publishUrl($targetUuid, 'de', '/' . $prefix . '-ziel');
    }

    /**
     * @param array{en: string, de: string} $titles
     * @param array{en: string, de: string} $urls
     */
    private function createPublishedPage(array $titles, array $urls, string $parentPath): string
    {
        /** @var PageDocument $page */
        $page = $this->documentManager->create('page');
        $page->setLocale('en');
        $page->setTitle($titles['en']);
        $page->setStructureType('default');
        $page->setResourceSegment($urls['en']);
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => $titles['en'],
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => $parentPath]);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'de', ['load_ghost_content' => false]);
        $page->setTitle($titles['de']);
        $page->setStructureType('default');
        $page->setResourceSegment($urls['de']);
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => $titles['de'],
        ]);

        $this->documentManager->persist($page, 'de');
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'de');
        $this->documentManager->flush();
        $this->documentManager->clear();

        return $uuid;
    }

    private function publishUrl(string $uuid, string $locale, string $url): void
    {
        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, $locale);
        $page->setResourceSegment($url);
        $this->documentManager->persist($page, $locale);
        $this->documentManager->flush();
        $this->documentManager->publish($page, $locale);
        $this->documentManager->flush();
        $this->documentManager->clear();
    }
}
