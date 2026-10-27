<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Seo\Service\FeedService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * `robots.txt` and the RSS feed.
 *
 * Both are public documents a machine reads, and both were missing: a crawler that finds no robots
 * file assumes it may crawl `/admin/`, and a portal with no feed cannot be followed.
 */
#[CoversClass(\CMS\Modules\Seo\Http\RobotsController::class)]
#[CoversClass(\CMS\Modules\Seo\Http\FeedController::class)]
final class RobotsAndFeedTest extends AppTestCase
{
    private ?int $authorId = null;

    private function author(): int
    {
        return $this->authorId ??= $this->makeUser('writer', 'contributor');
    }

    private function article(string $slug, string $title, string $status = 'published'): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'Excerpt for ' . $title,
            'author_id' => $this->author(),
            'status' => $status,
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    private function fetch(string $path): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host));
    }

    #[Test]
    public function robotsPointsAtTheSitemapAndKeepsCrawlersOutOfTheAdmin(): void
    {
        $response = $this->fetch('/robots.txt');
        $body = $response->content();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('text/plain', (string) $response->header('Content-Type'));
        self::assertStringContainsString('Disallow: /admin', $body);
        self::assertStringContainsString('Disallow: /api/', $body);
        self::assertStringContainsString('Sitemap: ', $body);
        self::assertStringContainsString('/sitemap.xml', $body);
    }

    #[Test]
    public function theFeedListsPublishedArticlesAsRss(): void
    {
        $this->article('feed-one', 'Feed One');
        $this->article('feed-two', 'Feed Two');
        $this->article('feed-draft', 'A Draft', 'draft');

        $response = $this->fetch('/feed');
        $body = $response->content();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/rss+xml', (string) $response->header('Content-Type'));
        self::assertStringContainsString('<rss version="2.0"', $body);
        self::assertStringContainsString('<title>Feed One</title>', $body);
        self::assertStringContainsString('Excerpt for Feed One', $body);
        self::assertStringContainsString('<pubDate>', $body);
        self::assertStringNotContainsString('A Draft', $body, 'A draft must not appear in the feed.');
    }

    #[Test]
    public function feedXmlIsTheSameDocument(): void
    {
        $this->article('feed-one', 'Feed One');

        self::assertSame(200, $this->fetch('/feed.xml')->status());
        self::assertSame($this->fetch('/feed')->content(), $this->fetch('/feed.xml')->content());
    }

    #[Test]
    public function theFeedIsWrittenAsAnArtifactAndRegeneratedWhenStale(): void
    {
        $this->article('feed-one', 'Feed One');

        $feed = $this->app->container()->get(FeedService::class);

        self::assertSame(1, $feed->generate($this->siteId));
        self::assertNotNull($feed->read($this->siteId));

        // A second article appears after the artifact was written; regeneration must include it.
        $this->article('feed-two', 'Feed Two');
        $feed->generate($this->siteId);

        self::assertStringContainsString('Feed Two', (string) $feed->read($this->siteId));
    }

    #[Test]
    public function anUnknownHostHasNeitherDocument(): void
    {
        $request = Request::create('GET', '/robots.txt', 'unknown.example');

        self::assertSame(404, $this->app->handle($request)->status());
    }
}
