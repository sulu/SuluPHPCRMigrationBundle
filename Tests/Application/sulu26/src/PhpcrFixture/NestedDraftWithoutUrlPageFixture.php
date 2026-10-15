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
 * A draft page without a stored url below a published page that is not the homepage,
 * so the generated slug has to start with the slug of its parent.
 */
class NestedDraftWithoutUrlPageFixture implements PhpcrFixtureInterface
{
    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        private readonly SessionInterface $defaultSession,
    ) {
    }

    public function load(): void
    {
        /** @var PageDocument $parent */
        $parent = $this->documentManager->create('page');
        $parent->setLocale('en');
        $parent->setTitle('Generated Parent');
        $parent->setStructureType('default');
        $parent->setResourceSegment('/generated-parent');
        $parent->setWorkflowStage(WorkflowStage::PUBLISHED);
        $parent->getStructure()->bind([
            'title' => 'Generated Parent',
        ]);

        $this->documentManager->persist($parent, 'en', ['parent_path' => '/cmf/website/contents']);
        $this->documentManager->flush();
        $this->documentManager->publish($parent, 'en');
        $this->documentManager->flush();

        $parentPath = $parent->getPath();
        $this->documentManager->clear();

        /** @var PageDocument $child */
        $child = $this->documentManager->create('page');
        $child->setLocale('en');
        $child->setTitle('Generated Child');
        $child->setStructureType('default');
        $child->setResourceSegment('/generated-parent/generated-child');
        $child->setWorkflowStage(WorkflowStage::TEST);
        $child->getStructure()->bind([
            'title' => 'Generated Child',
        ]);

        $this->documentManager->persist($child, 'en', ['parent_path' => $parentPath]);
        $this->documentManager->flush();

        $uuid = $child->getUuid();
        $this->documentManager->clear();

        $node = $this->defaultSession->getNodeByIdentifier($uuid);
        $node->getProperty('i18n:en-url')->remove();
        $this->defaultSession->save();
    }
}
