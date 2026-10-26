<?php

declare(strict_types=1);

/*
 * Cron entry point.
 *
 *   php bin/cron.php
 *
 * Two nested locks protect this run:
 *   - `cron` serialises the whole job runner, so two overlapping scheduler
 *     invocations cannot interleave;
 *   - the scheduler additionally takes its own named lock, which means running
 *     the scheduler directly (or from another entry point) is still safe.
 *
 * Jobs are registered explicitly. A job that throws is reported and does not
 * prevent the remaining jobs from running, because one broken maintenance task
 * must not silently stop scheduled publishing.
 */

use CMS\Core\Filesystem\FileLock;
use CMS\Modules\Editorial\Contracts\SchedulerServiceInterface;
use CMS\Modules\Notification\Contracts\NotificationServiceInterface;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Shared\Contracts\ClockInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

/*
 * Boot through the real composition root.
 *
 * This file used to duplicate the provider list, which meant every new module had to be
 * remembered in two places -- and the copy had already drifted (no Comment, Analytics,
 * Advertising, Notification, Plugin, Search, Seo, Theme or Cache). Requiring
 * `app/bootstrap.php` makes that class of bug impossible: cron and HTTP share one wiring.
 */
$app = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$app->boot();

$container = $app->container();
$storage = $root . DIRECTORY_SEPARATOR . 'storage';

$lock = new FileLock($storage . DIRECTORY_SEPARATOR . 'locks');

if (!$lock->acquire('cron')) {
    fwrite(STDOUT, "Another cron run is already in progress; exiting.\n");
    exit(0);
}

$exitCode = 0;

try {
    /** @var ClockInterface $clock */
    $clock = $container->get(ClockInterface::class);

    fwrite(STDOUT, sprintf("Cron started at %s\n", $clock->now()->format(DATE_ATOM)));

    // Job: publish content whose scheduled time has arrived.
    try {
        /** @var SchedulerServiceInterface $scheduler */
        $scheduler = $container->get(SchedulerServiceInterface::class);

        $published = $scheduler->publishDue();

        fwrite(STDOUT, sprintf("Scheduled publishing: %d item(s) published.\n", $published));
    } catch (Throwable $exception) {
        $exitCode = 1;
        fwrite(STDERR, 'Scheduled publishing failed: ' . $exception->getMessage() . PHP_EOL);
    }

    // Job: drain the notification email outbox.
    //
    // Email is queued rather than sent inline so a publish request never waits on a mail
    // server. This is the drain: no long-running worker, which the production constraints
    // forbid. A bounded number of retries is handled inside the service, so a permanently
    // invalid address ends up as `failed` instead of being retried forever.
    try {
        /** @var NotificationServiceInterface $notifications */
        $notifications = $container->get(NotificationServiceInterface::class);

        $result = $notifications->processOutbox();

        fwrite(STDOUT, sprintf(
            "Notification email: %d sent, %d failed.\n",
            $result['sent'],
            $result['failed']
        ));
    } catch (Throwable $exception) {
        $exitCode = 1;
        fwrite(STDERR, 'Notification outbox failed: ' . $exception->getMessage() . PHP_EOL);
    }

    // Job: regenerate the sitemap for every site.
    //
    // The sitemap is a generated artifact, so rebuilding it must never happen on a
    // crawler request. It runs across all sites because a newly added site would
    // otherwise stay uncrawlable until someone remembered a manual step.
    try {
        /** @var SiteServiceInterface $sites */
        $sites = $container->get(SiteServiceInterface::class);
        /** @var SitemapServiceInterface $sitemaps */
        $sitemaps = $container->get(SitemapServiceInterface::class);

        $urls = 0;
        $siteCount = 0;

        foreach ($sites->all() as $site) {
            $urls += $sitemaps->generate($site->id());
            $siteCount++;
        }

        fwrite(STDOUT, sprintf("Sitemap: %d URL(s) across %d site(s).\n", $urls, $siteCount));
    } catch (Throwable $exception) {
        $exitCode = 1;
        fwrite(STDERR, 'Sitemap generation failed: ' . $exception->getMessage() . PHP_EOL);
    }

    fwrite(STDOUT, "Cron finished.\n");
} finally {
    $lock->release('cron');
}

exit($exitCode);
