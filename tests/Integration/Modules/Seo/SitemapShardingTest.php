<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * A sitemap that outgrows one file.
 *
 * The protocol caps a sitemap at 50,000 URLs. The previous version simply stopped there, so a
 * larger site silently lost every article past the cap — the kind of truncation that looks like a
 * working sitemap and quietly deindexes content.
 *
 * The shard size is a parameter so these tests can prove the splitting without inserting fifty
 * thousand rows.
 */
#[CoversClass(\CMS\Modules\Seo\Service\SitemapService::class)]
#[CoversClass(\CMS\Modules\Seo\Http\SitemapController::class)]
final class SitemapShardingTest extends AppTestCase
{
    private ?int $authorId = null;

    private function author(): int
    {
        return $this->authorId ??= $this->makeUser('writer', 'contributor');
    }

    private function publish(int $count): void
    {
        $contents = $this->app->container()->get(ContentServiceInterface::class);

        for ($i = 1; $i <= $count; $i++) {
            $contents->create($this->siteId, [
                'type' => 'post',
                'title' => 'Sitemap Post ' . $i,
                'slug' => 'sitemap-post-' . $i,
                'author_id' => $this->author(),
                'status' => 'published',
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
            ]);
        }
    }

    private function sitemaps(): SitemapServiceInterface
    {
        return $this->app->container()->get(SitemapServiceInterface::class);
    }

    private function fetch(string $path): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host));
    }

    #[Test]
    public function aSmallSiteGetsASingleUrlsetAndNoShards(): void
    {
        $this->publish(3);

        $total = $this->sitemaps()->generate($this->siteId);

        self::assertSame(3, $total);

        $xml = (string) $this->sitemaps()->read($this->siteId);
        self::assertStringContainsString('<urlset', $xml);
        self::assertStringNotContainsString('<sitemapindex', $xml);
        self::assertNull($this->sitemaps()->readShard($this->siteId, 1), 'No shard file for a small site.');
    }

    #[Test]
    public function aLargeSiteGetsAnIndexAndShards(): void
    {
        $this->publish(5);

        // Five URLs in files of two: three shards.
        $total = $this->sitemaps()->generate($this->siteId, 2);

        self::assertSame(5, $total);

        $index = (string) $this->sitemaps()->read($this->siteId);

        self::assertStringContainsString('<sitemapindex', $index);
        self::assertStringContainsString('/sitemap-1.xml', $index);
        self::assertStringContainsString('/sitemap-2.xml', $index);
        self::assertStringContainsString('/sitemap-3.xml', $index);
        self::assertStringNotContainsString('/sitemap-4.xml', $index);

        // Every shard is a real urlset, and together they cover the site.
        $urls = 0;

        for ($shard = 1; $shard <= 3; $shard++) {
            $xml = $this->sitemaps()->readShard($this->siteId, $shard);

            self::assertNotNull($xml, 'Shard ' . $shard . ' must exist.');
            self::assertStringContainsString('<urlset', (string) $xml);
            $urls += substr_count((string) $xml, '<loc>');
        }

        self::assertSame(5, $urls, 'No URL may be lost across the split.');
    }

    #[Test]
    public function theIndexIsServedAtTheUsualPathAndShardsBesideIt(): void
    {
        $this->publish(3);
        $this->sitemaps()->generate($this->siteId, 2);

        $index = $this->fetch('/sitemap.xml');

        self::assertSame(200, $index->status());
        self::assertStringContainsString('<sitemapindex', $index->content());

        $first = $this->fetch('/sitemap-1.xml');

        self::assertSame(200, $first->status());
        self::assertStringContainsString('<urlset', $first->content());
        self::assertStringContainsString('application/xml', (string) $first->header('Content-Type'));
    }

    #[Test]
    public function aShardBeyondTheCountIsA404(): void
    {
        $this->publish(3);
        $this->sitemaps()->generate($this->siteId, 2);

        $response = $this->fetch('/sitemap-9.xml');
        self::assertSame(404, $response->status(), 'body: ' . substr($response->content(), 0, 300));
    }

    #[Test]
    public function aMalformedShardNameIsA404(): void
    {
        $this->publish(2);
        $this->sitemaps()->generate($this->siteId, 1);

        foreach (['/sitemap-abc.xml', '/sitemap-.xml', '/sitemap-1a.xml'] as $path) {
            self::assertSame(404, $this->fetch($path)->status(), $path);
        }
    }

    #[Test]
    public function shrinkingRemovesStaleShards(): void
    {
        $this->publish(5);
        $this->sitemaps()->generate($this->siteId, 2);

        self::assertNotNull($this->sitemaps()->readShard($this->siteId, 3));

        // The same site, now regenerated into a single file.
        $this->sitemaps()->generate($this->siteId, 100);

        self::assertNull($this->sitemaps()->readShard($this->siteId, 1), 'Stale shards must be removed.');
        self::assertNull($this->sitemaps()->readShard($this->siteId, 3));
        self::assertStringContainsString('<urlset', (string) $this->sitemaps()->read($this->siteId));
        self::assertSame(404, $this->fetch('/sitemap-1.xml')->status(), 'And they must not be served.');
    }

    #[Test]
    public function invalidatingRemovesTheIndexAndEveryShard(): void
    {
        $this->publish(5);
        $this->sitemaps()->generate($this->siteId, 2);

        $this->sitemaps()->invalidate($this->siteId);

        self::assertNull($this->sitemaps()->read($this->siteId));
        self::assertNull($this->sitemaps()->readShard($this->siteId, 1));
        self::assertSame(404, $this->fetch('/sitemap.xml')->status());
    }

    #[Test]
    public function anEmptySiteStillProducesAValidEmptyUrlset(): void
    {
        $total = $this->sitemaps()->generate($this->siteId);

        self::assertSame(0, $total);

        $xml = (string) $this->sitemaps()->read($this->siteId);

        self::assertStringContainsString('<urlset', $xml);
        self::assertStringContainsString('</urlset>', $xml);
    }
}
