<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Taking a site down, and bringing it back.
 *
 * `sites.status` existed and `SiteContextInterface::isActive()` existed, and **nothing in the public
 * path read either**: a deactivated site served pages exactly like an active one. The switch this
 * test exercises is the switch the PRD asked for.
 *
 * The cache is the interesting part. The page cache was consulted *before* the site was resolved, so
 * a page cached while the site was active would keep being served after it was switched off — the
 * status check would be a suggestion. The controller resolves the site first now, and deactivation
 * purges the site's cache as well.
 */
final class SiteStatusTest extends AdminTestCase
{
    private function sites(): SiteServiceInterface
    {
        return $this->app->container()->get(SiteServiceInterface::class);
    }

    private function article(string $slug): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Status article',
            'slug' => $slug,
            'excerpt' => 'A summary.',
            'author_id' => $this->makeUser('status-writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    #[Test]
    public function anInactiveSiteAnswers404EvenWhenItsPageWasCached(): void
    {
        $this->article('status-article');

        // Warm the cache while the site is active.
        $warm = $this->app->handle(Request::create('GET', '/blog/status-article', $this->host));
        self::assertSame(200, $warm->status());

        // Deactivation goes through the controller, which also purges the site's cache: the public
        // path checks status after the cache, so a cached page must not outlive the switch.
        $this->loginAs($this->makeUser('boss', 'administrator'));
        $this->post('/admin/sites/status', ['site_id' => $this->siteId, 'status' => 'inactive']);

        $response = $this->app->handle(Request::create('GET', '/blog/status-article', $this->host));

        self::assertSame(404, $response->status(), 'A cached page must not survive deactivation.');
    }

    #[Test]
    public function reactivatingTheSiteBringsThePageBack(): void
    {
        $this->article('status-back');

        $this->loginAs($this->makeUser('boss', 'administrator'));
        $this->post('/admin/sites/status', ['site_id' => $this->siteId, 'status' => 'inactive']);
        self::assertSame(404, $this->app->handle(Request::create('GET', '/blog/status-back', $this->host))->status());

        $this->post('/admin/sites/status', ['site_id' => $this->siteId, 'status' => 'active']);

        self::assertSame(200, $this->app->handle(Request::create('GET', '/blog/status-back', $this->host))->status());
    }

    #[Test]
    public function anUnknownStatusIsRefusedRatherThanStored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sites()->setStatus($this->siteId, 'disabled');
    }

    #[Test]
    public function theScreenTogglesTheStatusAndRecordsIt(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $html = $this->body($this->get('/admin/sites'));
        self::assertStringContainsString('Deactivate', $html);
        self::assertStringContainsString('badge--published">Active', $html);

        $response = $this->post('/admin/sites/status', [
            'site_id' => $this->siteId,
            'status' => 'inactive',
        ]);

        self::assertSame(303, $response->status());
        self::assertFalse($this->sites()->findById($this->siteId)->isActive());

        $after = $this->body($this->get('/admin/sites'));
        self::assertStringContainsString('Activate', $after);

        $audit = $this->app->container()->get(\CMS\Modules\Audit\Contracts\AuditLoggerInterface::class)
            ->entries($this->siteId, ['action' => 'site.status_changed']);

        self::assertCount(1, $audit);
        self::assertSame('inactive', $audit[0]->context['status'] ?? null);
    }

    #[Test]
    public function theFeedStopsToo(): void
    {
        $this->article('status-feed');

        $this->app->container()->get(\CMS\Modules\Seo\Service\FeedService::class)->generateAtom($this->siteId);
        self::assertSame(200, $this->app->handle(Request::create('GET', '/atom.xml', $this->host))->status());

        $this->loginAs($this->makeUser('boss', 'administrator'));
        $this->post('/admin/sites/status', ['site_id' => $this->siteId, 'status' => 'inactive']);

        // A reader keeps polling a feed long after people stop visiting the pages.
        self::assertSame(404, $this->app->handle(Request::create('GET', '/atom.xml', $this->host))->status());
        self::assertSame(404, $this->app->handle(Request::create('GET', '/feed', $this->host))->status());
    }
    #[Test]
    public function togglingRequiresTheSettingsCapability(): void
    {
        $this->loginAs($this->makeUser('editor-only', 'editor'));

        $response = $this->app->handle(Request::create('POST', '/admin/sites/status', $this->host, [], [
            '_token' => $this->app->container()->get(\CMS\Shared\Contracts\CsrfTokenManagerInterface::class)->token(),
            'site_id' => $this->siteId,
            'status' => 'inactive',
        ]));

        self::assertSame(403, $response->status());
        self::assertTrue($this->sites()->findById($this->siteId)->isActive());
    }
}
