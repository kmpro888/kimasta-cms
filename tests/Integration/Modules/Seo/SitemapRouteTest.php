<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Core\Http\Request;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * The public `/sitemap.xml` route.
 *
 * The generator was already tested; this covers the part that was missing, which is that
 * nothing served it. A generated artifact no crawler can fetch is worth nothing.
 */
#[CoversClass(\CMS\Modules\Seo\Http\SitemapController::class)]
final class SitemapRouteTest extends ApiTestCase
{
    private SitemapServiceInterface $sitemaps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sitemaps = $this->app->container()->get(SitemapServiceInterface::class);
    }

    #[Test]
    public function theGeneratedSitemapIsServedAsXml(): void
    {
        $this->publishArticle('Sitemap Subject');

        $this->sitemaps->generate($this->siteId);

        $response = $this->app->handle(Request::create('GET', '/sitemap.xml', $this->host));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/xml', (string) $response->header('Content-Type'));
        self::assertStringContainsString('<urlset', $response->content());
        self::assertStringContainsString('/blog/sitemap-subject', $response->content());
    }

    #[Test]
    public function anAbsentArtifactIsA404NotAnEmptySitemap(): void
    {
        // An empty <urlset> is a valid answer telling a crawler the site has no pages,
        // which is worse than "not ready yet".
        $this->sitemaps->invalidate($this->siteId);

        $response = $this->app->handle(Request::create('GET', '/sitemap.xml', $this->host));

        self::assertSame(404, $response->status());
    }

    #[Test]
    public function anUnknownHostIsA404(): void
    {
        $this->sitemaps->generate($this->siteId);

        $response = $this->app->handle(Request::create('GET', '/sitemap.xml', 'not-a-site.test'));

        self::assertSame(404, $response->status());
    }

    #[Test]
    public function theThemeCatchAllDoesNotSwallowTheSitemap(): void
    {
        $this->sitemaps->generate($this->siteId);

        $response = $this->app->handle(Request::create('GET', '/sitemap.xml', $this->host));

        // A themed HTML 404 would mean the catch-all won the routing race.
        self::assertStringNotContainsString('<html', strtolower($response->content()));
        self::assertStringContainsString('xml', (string) $response->header('Content-Type'));
    }

    #[Test]
    public function theSitemapIsNotRegeneratedOnRequest(): void
    {
        // Serving must be a file read: a crawler is exactly the traffic that must not
        // trigger a content scan.
        $this->publishArticle('Generated Before Request');
        $this->sitemaps->generate($this->siteId);

        $before = $this->sitemaps->read($this->siteId);

        $this->app->handle(Request::create('GET', '/sitemap.xml', $this->host));

        self::assertSame($before, $this->sitemaps->read($this->siteId));

        // Content published after generation does not appear until the artifact is rebuilt.
        $this->publishArticle('Published After Generation');

        self::assertStringNotContainsString(
            'published-after-generation',
            (string) $this->sitemaps->read($this->siteId)
        );
    }
}
