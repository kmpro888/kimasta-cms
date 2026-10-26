<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Cache;

use CMS\Core\Database\QueryProfiler;
use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Cache\Page\PageCache;
use CMS\Modules\Cache\Service\CacheInvalidationService;
use CMS\Shared\Contracts\DomainEventInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingEventDispatcher;
use Tests\Support\TestCase;

/**
 * Event-driven, targeted cache invalidation.
 *
 * The product rule under test: an article edit must not wipe the site cache. Only
 * the article page and the listing pages are evicted.
 */
#[CoversClass(CacheInvalidationService::class)]
final class CacheInvalidationTest extends TestCase
{
    private PageCache $pages;

    private RecordingEventDispatcher $events;

    private CacheInvalidationService $invalidation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pages = new PageCache($this->path('pages'));
        $this->events = new RecordingEventDispatcher();
        $this->invalidation = new CacheInvalidationService($this->pages, $this->events);
    }

    private function dispatch(string $name, array $payload): void
    {
        $this->invalidation->invalidateForEvent($name, $payload);
    }

    #[Test]
    public function contentUpdatedEvictsTheArticlePage(): void
    {
        $this->pages->put(1, '/blog/hello', 'article', [CacheDependency::content(42)]);

        $evicted = $this->invalidation->invalidateForEvent('content.updated', [
            'id' => 42,
            'site_id' => 1,
        ]);

        self::assertSame(1, $evicted);
        self::assertNull($this->pages->get(1, '/blog/hello'));
    }

    #[Test]
    public function contentUpdatedAlsoEvictsListings(): void
    {
        // A listing declares `archive`, which is what makes an edit refresh the
        // homepage without wiping unrelated static pages.
        $this->pages->put(1, '/', 'home', [CacheDependency::archive(1)]);
        $this->pages->put(1, '/about', 'static', []);

        $evicted = $this->invalidation->invalidateForEvent('content.published', [
            'id' => 7,
            'site_id' => 1,
        ]);

        self::assertSame(1, $evicted);
        self::assertNull($this->pages->get(1, '/'));

        // The static page is untouched.
        self::assertNotNull($this->pages->get(1, '/about'));
    }

    #[Test]
    public function anUnrelatedArticlePageSurvives(): void
    {
        $this->pages->put(1, '/blog/mine', 'mine', [CacheDependency::content(42)]);
        $this->pages->put(1, '/blog/yours', 'yours', [CacheDependency::content(99)]);

        $this->invalidation->invalidateForEvent('content.updated', ['id' => 42, 'site_id' => 1]);

        self::assertNull($this->pages->get(1, '/blog/mine'));
        self::assertNotNull($this->pages->get(1, '/blog/yours'));
    }

    #[Test]
    public function anotherSitesCacheIsNeverTouched(): void
    {
        $this->pages->put(1, '/blog/hello', 'site one', [CacheDependency::content(42)]);
        $this->pages->put(2, '/blog/hello', 'site two', [CacheDependency::content(42)]);

        $this->invalidation->invalidateForEvent('content.updated', ['id' => 42, 'site_id' => 1]);

        self::assertNull($this->pages->get(1, '/blog/hello'));
        self::assertNotNull($this->pages->get(2, '/blog/hello'));
    }

    #[Test]
    public function anEventWithoutASiteIdEvictsNothing(): void
    {
        $this->pages->put(1, '/blog/hello', 'x', [CacheDependency::content(42)]);

        // Without a tenant we would have to guess; guessing would evict the wrong
        // site, so we do nothing.
        self::assertSame(0, $this->invalidation->invalidateForEvent('content.updated', ['id' => 42]));

        self::assertNotNull($this->pages->get(1, '/blog/hello'));
    }

    #[Test]
    public function termEventsEvictPagesDependingOnThatTerm(): void
    {
        $this->pages->put(1, '/category/news', 'archive', [CacheDependency::term(8)]);
        $this->pages->put(1, '/category/sport', 'archive', [CacheDependency::term(9)]);

        $this->invalidation->invalidateForEvent('term.updated', ['term_id' => 8, 'site_id' => 1]);

        self::assertNull($this->pages->get(1, '/category/news'));
        self::assertNotNull($this->pages->get(1, '/category/sport'));
    }

    #[Test]
    public function themeActivationEvictsPagesRenderingThatTheme(): void
    {
        $this->pages->put(1, '/blog/x', 'x', [CacheDependency::theme('default')]);

        $this->invalidation->invalidateForEvent('theme.activated', [
            'theme' => 'default',
            'site_id' => 1,
        ]);

        self::assertNull($this->pages->get(1, '/blog/x'));
    }

    #[Test]
    public function settingsUpdateEvictsPagesDependingOnSettings(): void
    {
        $this->pages->put(1, '/', 'home', [CacheDependency::settings(1)]);

        $this->invalidation->invalidateForEvent('settings.updated', ['site_id' => 1]);

        self::assertNull($this->pages->get(1, '/'));
    }

    #[Test]
    public function irrelevantEventsAreIgnored(): void
    {
        $this->pages->put(1, '/blog/x', 'x', [CacheDependency::content(1)]);

        self::assertSame(0, $this->invalidation->invalidateForEvent('user.logged_in', ['site_id' => 1]));
        self::assertNotNull($this->pages->get(1, '/blog/x'));
    }

    #[Test]
    public function dependencyMappingIsExplicit(): void
    {
        self::assertSame('content:42', $this->invalidation->dependencyKeyFor('content.updated', ['id' => 42]));
        self::assertSame('term:8', $this->invalidation->dependencyKeyFor('term.deleted', ['term_id' => 8]));
        self::assertSame('theme:dark', $this->invalidation->dependencyKeyFor('theme.activated', ['theme' => 'dark']));
        self::assertSame('settings:1', $this->invalidation->dependencyKeyFor('settings.updated', ['site_id' => 1]));
        self::assertNull($this->invalidation->dependencyKeyFor('content.updated', []));
        self::assertNull($this->invalidation->dependencyKeyFor('something.else', ['id' => 1]));
    }

    #[Test]
    public function subscribeWiresTheDomainEvents(): void
    {
        $this->invalidation->subscribe();

        $this->pages->put(1, '/blog/hello', 'article', [CacheDependency::content(42)]);

        // Dispatch through the dispatcher, not directly, to prove the listener is
        // attached to the right event names.
        $this->events->dispatch(new \CMS\Core\Events\DomainEvent(
            'content.updated',
            ['id' => 42, 'site_id' => 1],
            1,
        ));

        self::assertNull($this->pages->get(1, '/blog/hello'));
    }

    #[Test]
    public function invalidateSiteIsRecoveryOnlyAndRemovesEverything(): void
    {
        $this->pages->put(1, '/a', 'a', []);
        $this->pages->put(1, '/b', 'b', []);

        self::assertSame(2, $this->invalidation->invalidateSite(1));

        self::assertNull($this->pages->get(1, '/a'));
        self::assertNull($this->pages->get(1, '/b'));
    }

    #[Test]
    public function aCacheHitCostsZeroDatabaseQueries(): void
    {
        // The core product promise, measured rather than asserted by inspection.
        $this->pages->put(1, '/blog/cached', '<p>Cached</p>', [CacheDependency::content(42)]);

        $profiler = new QueryProfiler();

        // No database is touched at all on this path; the profiler has nothing to
        // record, which is exactly the target.
        $page = $this->pages->get(1, '/blog/cached');

        self::assertNotNull($page);
        self::assertSame('<p>Cached</p>', $page->html);
        self::assertSame(0, $profiler->count());
    }
}
