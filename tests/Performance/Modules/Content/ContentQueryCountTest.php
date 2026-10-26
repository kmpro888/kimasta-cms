<?php

declare(strict_types=1);

namespace Tests\Performance\Modules\Content;

use CMS\Core\Database\QueryProfiler;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentQueryService;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Query-count regression guard for the public read path.
 *
 * The task pack sets the target: an uncached single-content retrieval must cost
 * at most 5 logical queries before optional relations. These assertions pin the
 * actual cost well below that, so a future change that adds a query per relation
 * or turns a join into a loop fails here instead of in production.
 */
final class ContentQueryCountTest extends ModuleDatabaseTestCase
{
    private QueryProfiler $profiler;

    private ContentQueryService $query;

    private ContentService $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = new PdoContentRepository($this->db);
        $registry = new PdoUrlRegistryRepository($this->db);

        $this->query = new ContentQueryService($repository, $registry);
        $this->writer = new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator($registry),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $this->profiler = new QueryProfiler();
    }

    /**
     * Run a callback with query recording enabled and return the query count.
     */
    private function countQueries(callable $work): int
    {
        $this->profiler->reset();
        $this->db->profiler($this->profiler);

        try {
            $work();
        } finally {
            $this->db->profiler(null);
        }

        return $this->profiler->count();
    }

    #[Test]
    public function singleContentByUrlCostsAtMostTwoQueries(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->writer->create($siteId, ['title' => 'Cheap Read', 'author_id' => 1]);

        $count = $this->countQueries(function () use ($siteId): void {
            $this->query->findByUrl($siteId, '/blog/cheap-read');
        });

        // One indexed registry lookup + one primary-key fetch.
        self::assertSame(2, $count);
    }

    #[Test]
    public function singleContentByUrlStaysInsideTheTaskPackTarget(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->writer->create($siteId, ['title' => 'Budget Read', 'author_id' => 1]);

        $count = $this->countQueries(function () use ($siteId): void {
            $this->query->findByUrl($siteId, '/blog/budget-read');
        });

        self::assertLessThanOrEqual(5, $count);
    }

    #[Test]
    public function aListingCostsTwoQueriesRegardlessOfRowCount(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        for ($i = 1; $i <= 10; $i++) {
            $this->writer->create($siteId, ['title' => 'Post ' . $i, 'author_id' => 1]);
        }

        $count = $this->countQueries(function () use ($siteId): void {
            $this->query->paginate($siteId, ['limit' => 10]);
        });

        // Items + total. No per-row query: there is no N+1 here.
        self::assertSame(2, $count);
    }

    #[Test]
    public function listingCostDoesNotGrowWithTheNumberOfRows(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->writer->create($siteId, ['title' => 'Solo', 'author_id' => 1]);

        $single = $this->countQueries(function () use ($siteId): void {
            $this->query->paginate($siteId, ['limit' => 10]);
        });

        for ($i = 2; $i <= 12; $i++) {
            $this->writer->create($siteId, ['title' => 'Post ' . $i, 'author_id' => 1]);
        }

        $many = $this->countQueries(function () use ($siteId): void {
            $this->query->paginate($siteId, ['limit' => 10]);
        });

        self::assertSame($single, $many);
    }

    #[Test]
    public function theListingQueryNeverSelectsTheBlockPayload(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        for ($i = 1; $i <= 5; $i++) {
            $this->writer->create($siteId, [
                'title' => 'Heavy ' . $i,
                'author_id' => 1,
                'content_json' => [['type' => 'paragraph', 'data' => ['text' => str_repeat('x', 2000)]]],
            ]);
        }

        $this->profiler->reset();
        $this->db->profiler($this->profiler);

        try {
            $this->query->paginate($siteId, ['limit' => 5]);
        } finally {
            $this->db->profiler(null);
        }

        foreach ($this->profiler->queries() as $query) {
            $sql = strtolower($query['sql']);

            if (!str_contains($sql, 'select')) {
                continue;
            }

            // A listing must never drag the canonical block JSON or compiled
            // HTML into memory; that is the whole point of ContentSummary.
            self::assertStringNotContainsString('content_json', $sql);
            self::assertStringNotContainsString('content_html', $sql);
        }
    }

    #[Test]
    public function creatingContentCostsABoundedNumberOfQueries(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $count = $this->countQueries(function () use ($siteId): void {
            $this->writer->create($siteId, [
                'title' => 'Create Cost',
                'author_id' => 1,
                'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'body']]],
            ]);
        });

        // slug availability + insert + read-back + url upsert. Publishing also
        // emits an event but that is in-process.
        self::assertLessThanOrEqual(6, $count);
    }

    #[Test]
    public function noQueryUsesASelectStar(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->writer->create($siteId, ['title' => 'Star Check', 'author_id' => 1]);

        $this->profiler->reset();
        $this->db->profiler($this->profiler);

        try {
            $this->query->findByUrl($siteId, '/blog/star-check');
            $this->query->paginate($siteId);
        } finally {
            $this->db->profiler(null);
        }

        foreach ($this->profiler->queries() as $query) {
            self::assertDoesNotMatchRegularExpression(
                '/select\s+\*/i',
                $query['sql'],
                'A hot-path query used SELECT *: ' . $query['sql']
            );
        }
    }
}
