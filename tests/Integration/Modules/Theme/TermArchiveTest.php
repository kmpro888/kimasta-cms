<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Database\Connection;
use CMS\Core\Database\QueryProfiler;
use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * A category archive, paged by the database.
 *
 * This used to resolve *every* content id for the term in the Taxonomy module and hand Content a
 * list of them, so a category holding 20,000 articles built an `IN (…20,000 placeholders…)` and the
 * page took 1.6 seconds at 50,000 rows. The repository now takes a `term_id` and pages with a
 * subquery.
 *
 * The tests below pin the behaviour that must not change (which articles appear, in what order,
 * paged correctly, tenant-isolated) and the shape that must not come back: a query with thousands
 * of bindings.
 */
#[CoversClass(\CMS\Modules\Content\Repository\PdoContentRepository::class)]
#[CoversClass(\CMS\Modules\Theme\Http\PublicPageController::class)]
final class TermArchiveTest extends AppTestCase
{
    private int $authorId = 0;

    private function content(): ContentServiceInterface
    {
        return $this->app->container()->get(ContentServiceInterface::class);
    }

    private function taxonomy(): TaxonomyServiceInterface
    {
        return $this->app->container()->get(TaxonomyServiceInterface::class);
    }

    private function author(): int
    {
        return $this->authorId > 0 ? $this->authorId : ($this->authorId = $this->makeUser('writer', 'contributor'));
    }

    /**
     * @return array{term: int, contents: list<int>}
     */
    private function seedTerm(string $slug, int $published, int $drafts = 0): array
    {
        // ensureTaxonomy takes a registered taxonomy ('category'), not a free name.
        $this->taxonomy()->ensureTaxonomy($this->siteId, 'category');
        $term = $this->taxonomy()->createTerm($this->siteId, 'category', ucfirst($slug), null, null, $slug);

        $ids = [];

        for ($i = 1; $i <= $published + $drafts; $i++) {
            $status = $i <= $published ? 'published' : 'draft';
            $item = $this->content()->create($this->siteId, [
                'type' => 'post',
                'title' => $slug . ' article ' . $i,
                'slug' => $slug . '-article-' . $i,
                'author_id' => $this->author(),
                'status' => $status,
                'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
            ]);

            $this->taxonomy()->attach((int) $item->id, (int) $term->id, $this->siteId);
            $ids[] = (int) $item->id;
        }

        return ['term' => (int) $term->id, 'contents' => $ids];
    }

    private function fetch(string $path, array $query = []): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host, $query));
    }

    #[Test]
    public function itListsOnlyThatTermsPublishedArticles(): void
    {
        $this->seedTerm('news', 3);
        $this->seedTerm('opinion', 2);

        $html = $this->fetch('/term/news')->content();

        self::assertStringContainsString('news article 1', $html);
        self::assertStringContainsString('news article 3', $html);
        self::assertStringNotContainsString('opinion article', $html, 'Another term must not appear.');
    }

    #[Test]
    public function unpublishedArticlesAreNotListed(): void
    {
        $this->seedTerm('news', 2, 2);

        $html = $this->fetch('/term/news')->content();

        self::assertStringContainsString('news article 1', $html);
        self::assertStringNotContainsString('news article 3', $html, 'A draft must not be listed.');
    }

    #[Test]
    public function itPagesCorrectly(): void
    {
        $this->seedTerm('news', 25);

        $first = $this->fetch('/term/news')->content();
        $third = $this->fetch('/term/news', ['page' => '3'])->content();

        self::assertSame(10, substr_count($first, 'class="card"') ?: substr_count($first, '<article'), 'Ten per page.');
        self::assertStringContainsString('news article 25', $first, 'Newest first.');
        self::assertStringContainsString('news article 5', $third);
        self::assertStringNotContainsString('news article 25', $third, 'Page three is not page one.');
    }

    #[Test]
    public function anotherTenantsTermIsA404(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->taxonomy()->ensureTaxonomy($otherSite, 'category');
        $this->taxonomy()->createTerm($otherSite, 'category', 'Theirs', null, null, 'theirs');

        self::assertSame(404, $this->fetch('/term/theirs')->status());
    }

    #[Test]
    public function theArchiveDoesNotBuildAnIdList(): void
    {
        $this->seedTerm('news', 40);

        /** @var Connection $db */
        $db = $this->app->container()->get(Connection::class);
        $profiler = new QueryProfiler();
        $db->profiler($profiler);

        $response = $this->fetch('/term/news');

        $db->profiler(null);

        self::assertSame(200, $response->status());
        self::assertLessThanOrEqual(12, $profiler->count(), 'A term archive should stay a handful of queries.');

        foreach ($profiler->queries() as $query) {
            $bindings = is_array($query) ? ($query['bindings'] ?? []) : [];

            self::assertLessThan(
                50,
                is_array($bindings) ? count($bindings) : 0,
                'No query may carry an id list again; this is the regression that cost 1.6 seconds.'
            );
        }
    }
}
