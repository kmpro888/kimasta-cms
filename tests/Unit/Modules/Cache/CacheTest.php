<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Cache;

use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Cache\FileCache;
use CMS\Modules\Cache\Page\PageCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Cache internals: object cache, page cache and dependency index.
 *
 * The properties enforced here are the ones the product promise rests on: a hit is
 * one file read, a corrupt entry degrades to a miss, and one tenant's cache is
 * never another's.
 */
#[CoversClass(FileCache::class)]
#[CoversClass(PageCache::class)]
#[CoversClass(CacheDependency::class)]
final class CacheTest extends TestCase
{
    private function pageCache(): PageCache
    {
        return new PageCache($this->path('pages'));
    }

    #[Test]
    public function fileCacheRoundTripsAValue(): void
    {
        $cache = new FileCache($this->path('objects'));
        $cache->put('greeting', 'hello', 60);

        self::assertSame('hello', $cache->get('greeting'));
    }

    #[Test]
    public function fileCacheSurvivesANewInstance(): void
    {
        (new FileCache($this->path('objects')))->put('key', ['a' => 1], 60);

        self::assertSame(['a' => 1], (new FileCache($this->path('objects')))->get('key'));
    }

    #[Test]
    public function expiredEntriesAreDropped(): void
    {
        $cache = new FileCache($this->path('objects'));
        $cache->put('short', 'value', 0);

        // TTL 0 means "already expired" by design.
        self::assertNull((new FileCache($this->path('objects')))->get('short'));
    }

    #[Test]
    public function corruptEntriesAreTreatedAsAMissAndDeleted(): void
    {
        $directory = $this->path('objects');
        mkdir($directory, 0775, true);

        // A file with no header line is corrupt.
        file_put_contents($directory . DIRECTORY_SEPARATOR . sha1('bad') . '.cache', 'not-a-cache-entry');

        $cache = new FileCache($directory);

        self::assertNull($cache->get('bad'));
        self::assertFileDoesNotExist($directory . DIRECTORY_SEPARATOR . sha1('bad') . '.cache');
    }

    #[Test]
    public function forgetRemovesTheEntry(): void
    {
        $cache = new FileCache($this->path('objects'));
        $cache->put('key', 'value', 60);
        $cache->forget('key');

        self::assertNull((new FileCache($this->path('objects')))->get('key'));
    }

    #[Test]
    public function pageCacheStoresAndReturnsHtml(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/blog/hello', '<p>Hello</p>', []);

        $page = $cache->get(1, '/blog/hello');

        self::assertNotNull($page);
        self::assertSame('<p>Hello</p>', $page->html);
    }

    #[Test]
    public function pageCacheMissReturnsNull(): void
    {
        self::assertNull($this->pageCache()->get(1, '/never-stored'));
    }

    #[Test]
    public function pageCacheNormalizesPaths(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/blog/hello', '<p>Hi</p>', []);

        self::assertNotNull($cache->get(1, '/blog/hello/?utm=x'));
        self::assertNotNull($cache->get(1, 'blog/hello'));
    }

    #[Test]
    public function dependencyIndexRecordsWhatAPageDependsOn(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/blog/hello', '<p>Hello</p>', [
            CacheDependency::content(42),
            CacheDependency::term(8),
        ]);

        $index = $cache->loadIndex(1);

        self::assertArrayHasKey('content:42', $index);
        self::assertArrayHasKey('term:8', $index);
        self::assertArrayHasKey('/blog/hello', $index['content:42']);
    }

    #[Test]
    public function invalidatingADependencyEvictsOnlyItsPages(): void
    {
        $cache = $this->pageCache();

        $cache->put(1, '/blog/hello', '<p>Hello</p>', [CacheDependency::content(42)]);
        $cache->put(1, '/blog/other', '<p>Other</p>', [CacheDependency::content(99)]);
        $cache->put(1, '/about', '<p>About</p>', [CacheDependency::content(42)]);

        $removed = $cache->forgetByDependency(1, 'content:42');

        self::assertSame(2, $removed);
        self::assertNull($cache->get(1, '/blog/hello'));
        self::assertNull($cache->get(1, '/about'));

        // The unrelated page survives: this is the whole point of targeting.
        self::assertNotNull($cache->get(1, '/blog/other'));
    }

    #[Test]
    public function invalidationReportsZeroForAnUnknownDependency(): void
    {
        self::assertSame(0, $this->pageCache()->forgetByDependency(1, 'content:404'));
    }

    #[Test]
    public function pageCacheIsPartitionedBySite(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/blog/hello', 'site one', []);
        $cache->put(2, '/blog/hello', 'site two', []);

        self::assertSame('site one', $cache->get(1, '/blog/hello')?->html);
        self::assertSame('site two', $cache->get(2, '/blog/hello')?->html);
    }

    #[Test]
    public function invalidatingOneSiteLeavesTheOtherIntact(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/blog/hello', 'one', [CacheDependency::content(1)]);
        $cache->put(2, '/blog/hello', 'two', [CacheDependency::content(1)]);

        $cache->forgetByDependency(1, 'content:1');

        self::assertNull($cache->get(1, '/blog/hello'));
        self::assertNotNull($cache->get(2, '/blog/hello'));
    }

    #[Test]
    public function flushSiteRemovesEverythingForOneTenant(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/a', 'a', []);
        $cache->put(1, '/b', 'b', []);
        $cache->put(2, '/a', 'other', []);

        self::assertSame(2, $cache->flushSite(1));

        self::assertNull($cache->get(1, '/a'));
        self::assertNull($cache->get(1, '/b'));
        self::assertNotNull($cache->get(2, '/a'));
    }

    #[Test]
    public function expiredPageCacheEntriesAreMisses(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/expired', 'old', [], 0);

        self::assertNull($cache->get(1, '/expired'));
    }

    #[Test]
    public function corruptPageCacheEntriesAreDeleted(): void
    {
        $cache = $this->pageCache();
        $cache->put(1, '/broken', 'x', []);

        $file = $cache->pagesDirectory(1) . DIRECTORY_SEPARATOR . sha1('/broken') . '.html';
        file_put_contents($file, 'no header here');

        self::assertNull($cache->get(1, '/broken'));
        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function eligibilityAllowsOnlyAnonymousPublicReads(): void
    {
        $cache = $this->pageCache();

        self::assertTrue($cache->isEligible('GET', false, false, true));
        self::assertTrue($cache->isEligible('HEAD', false, false, true));

        // Never cache a write, a logged-in page, a preview, or a non-public page.
        self::assertFalse($cache->isEligible('POST', false, false, true));
        self::assertFalse($cache->isEligible('PUT', false, false, true));
        self::assertFalse($cache->isEligible('GET', true, false, true));
        self::assertFalse($cache->isEligible('GET', false, true, true));
        self::assertFalse($cache->isEligible('GET', false, false, false));
    }

    #[Test]
    public function keyForIncludesTheSiteAndNormalizedPath(): void
    {
        self::assertSame('3:/blog/x', $this->pageCache()->keyFor(3, '/blog/x/'));
    }

    #[Test]
    public function dependencyKeysAreStable(): void
    {
        self::assertSame('content:42', CacheDependency::content(42)->key());
        self::assertSame('term:8', CacheDependency::term(8)->key());
        self::assertSame('theme:default', CacheDependency::theme('default')->key());
        self::assertSame('settings:1', CacheDependency::settings(1)->key());
    }

    #[Test]
    public function aDependencyNeedsBothParts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CacheDependency('', 'x');
    }
}
