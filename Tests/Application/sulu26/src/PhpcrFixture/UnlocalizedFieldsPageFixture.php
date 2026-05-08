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

class UnlocalizedFieldsPageFixture implements PhpcrFixtureInterface
{
    private const JSON_LINE = '{"foo":"bar","count":42}';
    private const JSON_AREA = '[{"id":1,"label":"first"},{"id":2,"label":"second"}]';

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
        $page->setTitle('Unlocalized Fields Page');
        $page->setStructureType('unlocalized-fields');
        $page->setResourceSegment('/unlocalized-fields-page');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Unlocalized Fields Page',
        ]);

        $this->documentManager->persist($page, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'en');
        $this->documentManager->flush();

        $uuid = $page->getUuid();
        $this->documentManager->clear();

        /** @var PageDocument $page */
        $page = $this->documentManager->find($uuid, 'de', ['load_ghost_content' => false]);
        $page->setTitle('Seite mit unlokalisierten Feldern');
        $page->setStructureType('unlocalized-fields');
        $page->setResourceSegment('/seite-mit-unlokalisierten-feldern');
        $page->setWorkflowStage(WorkflowStage::PUBLISHED);
        $page->getStructure()->bind([
            'title' => 'Seite mit unlokalisierten Feldern',
        ]);

        $this->documentManager->persist($page, 'de');
        $this->documentManager->flush();
        $this->documentManager->publish($page, 'de');
        $this->documentManager->flush();
        $this->documentManager->clear();

        // Sulu 2.6's PageDocument structure bind() does not persist `multilingual="false"`
        // properties — the localized binding path silently drops them. To produce the same
        // PHPCR storage shape that real-world content with non-multilingual fields has
        // (bare property names without the `i18n:{locale}-` prefix), we set them directly
        // on the underlying PHPCR node in both the default and live workspaces.
        $this->writeUnlocalizedProperties($this->defaultSession, $uuid);
        $this->writeUnlocalizedProperties($this->liveSession, $uuid);
    }

    private function writeUnlocalizedProperties(SessionInterface $session, string $uuid): void
    {
        $node = $session->getNodeByIdentifier($uuid);
        $node->setProperty('unlocalizedJsonLine', self::JSON_LINE);
        $node->setProperty('unlocalizedJsonArea', self::JSON_AREA);
        $session->save();
    }
}
