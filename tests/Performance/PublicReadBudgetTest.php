<?php

declare(strict_types=1);

namespace Tests\Performance;

use CMS\Core\Database\Connection;
use CMS\Core\Database\QueryProfiler;
use CMS\Core\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Query-count regression guard for the public read path.
 *
 * The task pack (section 10) sets these targets:
 *
 *   cached article       0 DB queries   <- MET, asserted below
 *   uncached article     5 queries      <- NOT MET, see below
 *   archive              5 queries      <- NOT MET, see below
 *
 * The 0-query promise holds: a visitor on a warm page costs nothing, which is the whole
 * point of the cache-first design.
 *
 * The uncached budget is **not** met and these tests do not pretend otherwise. A cold
 * article render costs about 18 queries and an archive about 10, because every optional
 * module contributes its own indexed lookup on the same request: site context, redirect,
 * url registry, content, SEO, author, terms (2), settings (2), ads (2), and the theme.
 * That is a structural cost of a modular render, not a missing index and not an N+1.
 *
 * Per-request memoisation was added where the same row was fetched repeatedly (a single
 * render used to fetch the site row six times); that removed the duplicates but cannot get
 * a modular render to five.
 *
 * So these assertions guard against **regression** from today's numbers rather than claim
 * the target. Closing the gap needs a design decision -- a second-level cache of settings
 * and SEO, or a single joined page query -- not a tweak, and that decision is recorded in
 * `docs/reports/Wave-9-Tracking.md` rather than hidden here.
 */
final class PublicReadBudgetTest extends ApiTestCase
{
    /** Rendered article ceilings, measured. Lower them when a real optimisation lands. */
    private const ARTICLE_CEILING = 20;

    private const ARCHIVE_CEILING = 12;

    private const NOT_FOUND_CEILING = 10;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->app->container()->get(Connection::class);
    }

    private function publish(string $title, string $slug): void
    {
        $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
            ->create($this->siteId, [
                'type' => 'post',
                'title' => $title,
                'slug' => $slug,
                'author_id' => 1,
                'status' => 'published',
                'excerpt' => 'An excerpt.',
                'content_json' => [
                    ['type' => 'heading', 'version' => 1, 'data' => ['text' => $title, 'level' => 2]],
                    ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body copy.']],
                ],
            ]);
    }

    /**
     * Run one public request and count the queries it caused.
     *
     * The profiler is attached to the container's connection: that is the one the
     * repositories use, and it is a different instance from the one the test harness holds
     * for fixtures. Measuring the wrong connection is an easy way to measure nothing.
     */
    private function measure(string $path): array
    {
        $profiler = new QueryProfiler();
        $this->connection->profiler($profiler);

        try {
            $response = $this->app->handle(Request::create('GET', $path, $this->host));
        } finally {
            $this->connection->profiler(null);
        }

        return [
            'status' => $response->status(),
            'cache' => $response->header('X-Cache'),
            'queries' => $profiler->count(),
        ];
    }

    #[Test]
    public function aCachedArticleCostsZeroQueries(): void
    {
        $this->publish('Budget One', 'budget-one');

        // Warm the cache first: the measurement is about the hit path.
        $this->measure('/blog/budget-one');

        $hit = $this->measure('/blog/budget-one');

        self::assertSame(200, $hit['status']);
        self::assertSame('HIT', $hit['cache']);
        self::assertSame(0, $hit['queries'], 'A cache hit must not touch the database.');
    }

    #[Test]
    public function anUncachedArticleDoesNotRegress(): void
    {
        $this->publish('Budget Two', 'budget-two');

        $cold = $this->measure('/blog/budget-two');

        self::assertSame(200, $cold['status']);
        self::assertSame('MISS', $cold['cache']);
        self::assertLessThanOrEqual(
            self::ARTICLE_CEILING,
            $cold['queries'],
            'An uncached article cost ' . $cold['queries'] . ' queries, above the measured '
            . self::ARTICLE_CEILING . ' ceiling. The task-pack target is 5 and is not met.'
        );
    }

    #[Test]
    public function anArchiveDoesNotRegress(): void
    {
        $this->publish('Budget Three', 'budget-three');
        $this->publish('Budget Four', 'budget-four');

        $cold = $this->measure('/blog');

        self::assertSame(200, $cold['status']);
        self::assertLessThanOrEqual(
            self::ARCHIVE_CEILING,
            $cold['queries'],
            'An archive cost ' . $cold['queries'] . ' queries, above the measured '
            . self::ARCHIVE_CEILING . ' ceiling. The task-pack target is 5 and is not met.'
        );
    }

    #[Test]
    public function aNotFoundPageDoesNotRegress(): void
    {
        // A 404 is the most common request on a site with decaying links, so it deserves a
        // guard as much as a hit does.
        $response = $this->measure('/this-page-never-existed');

        self::assertSame(404, $response['status']);
        self::assertLessThanOrEqual(self::NOT_FOUND_CEILING, $response['queries']);
    }
}
