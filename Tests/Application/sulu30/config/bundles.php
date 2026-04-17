<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use CmsIg\Seal\Integration\Symfony\SealBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use FOS\HttpCacheBundle\FOSHttpCacheBundle;
use FOS\JsRoutingBundle\FOSJsRoutingBundle;
use FOS\RestBundle\FOSRestBundle;
use JMS\SerializerBundle\JMSSerializerBundle;
use League\FlysystemBundle\FlysystemBundle;
use Massive\Bundle\BuildBundle\MassiveBuildBundle;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle;
use Sulu\Article\Infrastructure\Symfony\HttpKernel\SuluArticleBundle;
use Sulu\Bundle\ActivityBundle\SuluActivityBundle;
use Sulu\Bundle\AdminBundle\SuluAdminBundle;
use Sulu\Bundle\CategoryBundle\SuluCategoryBundle;
use Sulu\Bundle\ContactBundle\SuluContactBundle;
use Sulu\Bundle\CoreBundle\SuluCoreBundle;
use Sulu\Bundle\HashBundle\SuluHashBundle;
use Sulu\Bundle\HttpCacheBundle\SuluHttpCacheBundle;
use Sulu\Bundle\LocationBundle\SuluLocationBundle;
use Sulu\Bundle\MarkupBundle\SuluMarkupBundle;
use Sulu\Bundle\MediaBundle\SuluMediaBundle;
use Sulu\Bundle\PersistenceBundle\SuluPersistenceBundle;
use Sulu\Bundle\PreviewBundle\SuluPreviewBundle;
use Sulu\Bundle\ReferenceBundle\SuluReferenceBundle;
use Sulu\Bundle\SecurityBundle\SuluSecurityBundle;
use Sulu\Bundle\TagBundle\SuluTagBundle;
use Sulu\Bundle\TestBundle\SuluTestBundle;
use Sulu\Bundle\TrashBundle\SuluTrashBundle;
use Sulu\Bundle\WebsiteBundle\SuluWebsiteBundle;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\SuluContentBundle;
use Sulu\Bundle\PhpcrMigrationBundle\SuluPhpcrMigrationBundle;
use Sulu\CustomUrl\Infrastructure\Symfony\HttpKernel\SuluCustomUrlBundle;
use Sulu\Messenger\Infrastructure\Symfony\HttpKernel\SuluMessengerBundle;
use Sulu\Page\Infrastructure\Symfony\HttpKernel\SuluPageBundle;
use Sulu\Route\Infrastructure\Symfony\HttpKernel\SuluRouteBundle;
use Sulu\Search\Infrastructure\Symfony\HttpKernel\SuluSearchBundle;
use Sulu\Snippet\Infrastructure\Symfony\HttpKernel\SuluSnippetBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Cmf\Bundle\RoutingBundle\CmfRoutingBundle;

return [
    FrameworkBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    MonologBundle::class => ['all' => true],
    SuluCoreBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineFixturesBundle::class => ['all' => true],
    FOSRestBundle::class => ['all' => true],
    JMSSerializerBundle::class => ['all' => true],
    FOSHttpCacheBundle::class => ['all' => true],
    SuluAdminBundle::class => ['all' => true],
    SuluPersistenceBundle::class => ['all' => true],
    SuluContactBundle::class => ['all' => true],
    SuluMediaBundle::class => ['all' => true],
    SuluSecurityBundle::class => ['all' => true],
    SuluCategoryBundle::class => ['all' => true],
    SuluTagBundle::class => ['all' => true],
    SuluWebsiteBundle::class => ['all' => true],
    SuluLocationBundle::class => ['all' => true],
    SuluHttpCacheBundle::class => ['all' => true],
    SuluHashBundle::class => ['all' => true],
    SuluMarkupBundle::class => ['all' => true],
    MassiveBuildBundle::class => ['all' => true],
    WebProfilerBundle::class => ['dev' => true, 'test' => true],
    SuluTestBundle::class => ['dev' => true, 'test' => true],
    DebugBundle::class => ['dev' => true, 'test' => true],
    SecurityBundle::class => ['all' => true],
    SuluPreviewBundle::class => ['all' => true],
    FOSJsRoutingBundle::class => ['all' => true],
    CmfRoutingBundle::class => ['all' => true, 'website' => true],
    StofDoctrineExtensionsBundle::class => ['all' => true],
    SuluActivityBundle::class => ['all' => true],
    SuluTrashBundle::class => ['all' => true],
    SuluReferenceBundle::class => ['all' => true],
    SchebTwoFactorBundle::class => ['all' => true],
    FlysystemBundle::class => ['all' => true],
    SuluContentBundle::class => ['all' => true],
    SuluRouteBundle::class => ['all' => true],
    SuluMessengerBundle::class => ['all' => true],
    SuluArticleBundle::class => ['all' => true],
    SuluSnippetBundle::class => ['all' => true],
    SuluPageBundle::class => ['all' => true],
    SuluSearchBundle::class => ['all' => true],
    SuluCustomUrlBundle::class => ['all' => true],
    SealBundle::class => ['all' => true],
    SuluPhpcrMigrationBundle::class => ['all' => true],
];
