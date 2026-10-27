<?php

declare(strict_types=1);

/*
 * Application bootstrap.
 *
 * Returns a configured Application instance. Kept tiny so the public entry
 * point stays readable and so tests can reuse the same construction.
 *
 * Module service providers are listed here explicitly, in dependency order.
 * There is deliberately no directory scanning or reflection autodiscovery: the
 * boot graph stays readable and a new module cannot wire itself in by accident.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CMS\Core\Bootstrap\Application;
use CMS\Modules\Advertising\AdvertisingServiceProvider;
use CMS\Modules\Analytics\AnalyticsServiceProvider;
use CMS\Modules\Audit\AuditServiceProvider;
use CMS\Modules\Api\ApiServiceProvider;
use CMS\Modules\Block\BlockServiceProvider;
use CMS\Modules\Cache\CacheServiceProvider;
use CMS\Modules\Comment\CommentServiceProvider;
use CMS\Modules\Content\ContentServiceProvider;
use CMS\Modules\Contribution\ContributionServiceProvider;
use CMS\Modules\Editorial\EditorialServiceProvider;
use CMS\Modules\Identity\IdentityServiceProvider;
use CMS\Modules\Media\MediaServiceProvider;
use CMS\Modules\Migration\MigrationServiceProvider;
use CMS\Modules\Notification\NotificationServiceProvider;
use CMS\Modules\Plugin\PluginServiceProvider;
use CMS\Modules\Revision\RevisionServiceProvider;
use CMS\Modules\Search\SearchServiceProvider;
use CMS\Modules\Seo\SeoServiceProvider;
use CMS\Modules\Site\SiteServiceProvider;
use CMS\Modules\Taxonomy\TaxonomyServiceProvider;
use CMS\Modules\Theme\ThemeServiceProvider;
use CMS\Modules\Admin\AdminServiceProvider;

$application = Application::create(dirname(__DIR__));

// Core is registered by Application itself; module providers layer on top.
// Order matters in four places:
//  - Content registers the finders its consumers use, so it precedes Media,
//    Editorial and SEO.
//  - Block must come after Content so it can rebind the block compiler.
//  - Cache subscribes to domain events in boot(), so it must come after the
//    modules that emit them.
$application->provider(new SiteServiceProvider());
$application->provider(new IdentityServiceProvider());
$application->provider(new AuditServiceProvider());
$application->provider(new ContentServiceProvider());
$application->provider(new MediaServiceProvider());
$application->provider(new TaxonomyServiceProvider());
$application->provider(new RevisionServiceProvider());
$application->provider(new EditorialServiceProvider());
$application->provider(new ContributionServiceProvider());
$application->provider(new BlockServiceProvider());
$application->provider(new PluginServiceProvider());
$application->provider(new CommentServiceProvider());
$application->provider(new AnalyticsServiceProvider());
$application->provider(new AdvertisingServiceProvider());
$application->provider(new NotificationServiceProvider());
$application->provider(new MigrationServiceProvider());
$application->provider(new ApiServiceProvider());
$application->provider(new SearchServiceProvider());
$application->provider(new SeoServiceProvider());
$application->provider(new ThemeServiceProvider());
$application->provider(new AdminServiceProvider());
$application->provider(new CacheServiceProvider());

return $application;

