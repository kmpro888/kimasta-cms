<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Core\Database\Connection;
use CMS\Core\Database\QueryProfiler;
use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The editorial loop through the HTML admin.
 *
 * This is deliberately a second end-to-end test rather than a reuse of the JSON one
 * (`PublishFlowTest`): the API and the forms share services but not controllers, and the whole
 * point is that both surfaces reach the same published article.
 *
 *   contributor signs in -> writes -> submits
 *   reviewer asks for a revision -> contributor edits -> resubmits -> reviewer approves
 *   editor publishes -> a visitor reads the cached page
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\ContentController::class)]
final class AdminContentFlowTest extends AdminTestCase
{
    private int $contributor;

    private int $reviewer;

    private int $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contributor = $this->makeUser('writer', 'contributor');
        $this->reviewer = $this->makeUser('checker', 'reviewer');
        $this->editor = $this->makeUser('boss', 'editor');
    }

    private function statusOf(int $id): string
    {
        return $this->app->container()->get(ContentServiceInterface::class)
            ->get($id, $this->siteId)->status;
    }

    /**
     * Follow a redirect and return the article id it names.
     */
    private function idFromLocation(string $location): int
    {
        self::assertMatchesRegularExpression('#/admin/content/(\d+)$#', $location);

        return (int) basename($location);
    }

    #[Test]
    public function aContributorWritesAndAnEditorPublishesThroughTheInterface(): void
    {
        // ---- 1. The contributor signs in through the form --------------------
        $this->loginAs($this->contributor);

        $dashboard = $this->get('/admin');
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Dashboard', $this->body($dashboard));

        // ---- 2. New article --------------------------------------------------
        $form = $this->get('/admin/content/new');
        self::assertSame(200, $form->status());
        self::assertNotSame('', $this->tokenFromHtml($this->body($form)));

        $created = $this->post('/admin/content', [
            'title' => 'Harbour Report',
            'excerpt' => 'A report from the harbour.',
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Harbour Report', 'level' => 2]],
                ['type' => 'paragraph', 'data' => ['text' => 'The tide came in early.']],
            ],
        ]);

        self::assertSame(303, $created->status());
        $id = $this->idFromLocation($this->location($created));

        // The editor reopens with the text that was typed, blocks included.
        $editor = $this->get('/admin/content/' . $id);
        $html = $this->body($editor);

        self::assertSame(200, $editor->status());
        self::assertStringContainsString('Harbour Report', $html);
        self::assertStringContainsString('The tide came in early.', $html);
        self::assertStringContainsString('Submit for review', $html);

        // ---- 3. Submit for review -------------------------------------------
        $submitted = $this->post('/admin/content/' . $id . '/submit', ['note' => 'Ready for review.']);

        self::assertSame(303, $submitted->status());
        self::assertSame('submitted', $this->statusOf($id));

        // ---- 4. The reviewer asks for a revision ----------------------------
        $this->loginAs($this->reviewer);

        $queue = $this->body($this->get('/admin/content/' . $id . '/review'));
        self::assertStringContainsString('Harbour Report', $queue);

        $this->post('/admin/content/' . $id . '/transition', ['to' => 'review']);
        self::assertSame('review', $this->statusOf($id));

        $this->post('/admin/content/' . $id . '/transition', [
            'to' => 'revision_requested',
            'note' => 'Please add the tide table.',
        ]);
        self::assertSame('revision_requested', $this->statusOf($id));

        // ---- 5. The contributor edits and resubmits -------------------------
        $this->loginAs($this->contributor);

        $reopened = $this->get('/admin/content/' . $id);
        self::assertSame(200, $reopened->status());
        self::assertStringContainsString('The tide came in early.', $this->body($reopened));

        $edited = $this->post('/admin/content/' . $id . '/save', [
            'title' => 'Harbour Report',
            'excerpt' => 'A revised report from the harbour.',
            'blocks' => [
                ['type' => 'heading', 'data' => ['text' => 'Harbour Report', 'level' => 2]],
                ['type' => 'paragraph', 'data' => ['text' => 'The tide came in early. High water at noon.']],
            ],
        ]);

        self::assertSame(303, $edited->status());

        $resubmitted = $this->post('/admin/content/' . $id . '/submit', ['note' => 'Revision done.']);
        self::assertSame(303, $resubmitted->status());
        self::assertSame('submitted', $this->statusOf($id));

        // The new text really is stored, not just re-rendered from the form.
        self::assertStringContainsString(
            'High water at noon.',
            (string) $this->app->container()->get(ContentServiceInterface::class)->get($id, $this->siteId)->contentHtml
        );

        // ---- 6. The reviewer approves ---------------------------------------
        $this->loginAs($this->reviewer);

        $this->post('/admin/content/' . $id . '/transition', ['to' => 'review']);
        $this->post('/admin/content/' . $id . '/transition', ['to' => 'approved', 'note' => 'Good.']);

        self::assertSame('approved', $this->statusOf($id));

        // ---- 7. The editor publishes ----------------------------------------
        $this->loginAs($this->editor);

        $this->post('/admin/content/' . $id . '/transition', ['to' => 'published']);

        self::assertSame('published', $this->statusOf($id));

        // ---- 8. A visitor reads the cached page -----------------------------
        $slug = $this->app->container()->get(ContentServiceInterface::class)
            ->get($id, $this->siteId)->slug;

        $first = $this->app->handle(Request::create('GET', '/blog/' . $slug, $this->host));

        self::assertSame(200, $first->status());
        self::assertStringContainsString('Harbour Report', $first->content());
        self::assertStringContainsString('High water at noon.', $first->content());
        self::assertSame('MISS', $first->header('X-Cache'));

        // ---- 9. The second visitor costs no queries -------------------------
        $connection = $this->app->container()->get(Connection::class);
        $profiler = new QueryProfiler();
        $connection->profiler($profiler);

        try {
            $second = $this->app->handle(Request::create('GET', '/blog/' . $slug, $this->host));
        } finally {
            $connection->profiler(null);
        }

        self::assertSame('HIT', $second->header('X-Cache'));
        self::assertSame(0, $profiler->count());
        self::assertSame($first->content(), $second->content());

        // ---- 10. An edit is visible immediately -----------------------------
        $this->loginAs($this->editor);

        $this->post('/admin/content/' . $id . '/save', [
            'title' => 'Harbour Report Updated',
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Rewritten after publication.']],
            ],
        ]);

        $afterEdit = $this->app->handle(Request::create('GET', '/blog/' . $slug, $this->host));

        self::assertSame(200, $afterEdit->status());
        self::assertStringContainsString('Harbour Report Updated', $afterEdit->content());
        self::assertStringContainsString('Rewritten after publication.', $afterEdit->content());
        self::assertSame('MISS', $afterEdit->header('X-Cache'), 'The cache must have been invalidated.');
    }

    #[Test]
    public function theWorkflowButtonsMatchWhatTheServerAllows(): void
    {
        $this->loginAs($this->contributor);

        $created = $this->post('/admin/content', [
            'title' => 'Button Honesty',
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body.']]],
        ]);

        $id = $this->idFromLocation($this->location($created));

        // A contributor owns a draft: they may submit it, and must not be offered a publish.
        $html = $this->body($this->get('/admin/content/' . $id));

        self::assertStringContainsString('Submit for review', $html);
        self::assertStringNotContainsString('value="published"', $html);
    }

    #[Test]
    public function aBlankTrailingBlockIsNotPersisted(): void
    {
        $this->loginAs($this->contributor);

        $created = $this->post('/admin/content', [
            'title' => 'No Empty Blocks',
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Only this one.']],
                ['type' => 'paragraph', 'data' => ['text' => '']],
            ],
        ]);

        $id = $this->idFromLocation($this->location($created));

        $blocks = $this->app->container()->get(ContentServiceInterface::class)
            ->get($id, $this->siteId)->blocks();

        // The editor always renders one blank row for adding; it must not be stored.
        self::assertCount(1, $blocks);
        self::assertSame('Only this one.', $blocks[0]['data']['text']);
    }

    #[Test]
    public function categoriesCanBeAttachedFromTheEditor(): void
    {
        $taxonomy = $this->app->container()->get(\CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface::class);
        $term = $taxonomy->createTerm($this->siteId, 'category', 'Shipping');

        $this->loginAs($this->contributor);

        $created = $this->post('/admin/content', [
            'title' => 'Filed Somewhere',
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body.']]],
        ]);

        $id = $this->idFromLocation($this->location($created));

        $attached = $this->post('/admin/content/' . $id . '/terms', ['term_ids' => [(int) $term->id]]);

        self::assertSame(303, $attached->status());

        $grouped = $taxonomy->termsForContents($this->siteId, [$id]);
        self::assertCount(1, $grouped[$id] ?? []);
        self::assertSame('Shipping', $grouped[$id][0]->name);

        // Reopening shows it ticked, so the panel reflects reality after a save.
        $html = $this->body($this->get('/admin/content/' . $id));
        self::assertMatchesRegularExpression('/value="' . $term->id . '"\s+checked/', $html);
    }

    #[Test]
    public function seoOverridesCanBeSetFromTheEditor(): void
    {
        $this->loginAs($this->contributor);

        $created = $this->post('/admin/content', [
            'title' => 'Search Appearance',
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body.']]],
        ]);

        $id = $this->idFromLocation($this->location($created));

        $saved = $this->post('/admin/content/' . $id . '/seo', [
            'meta_title' => 'A hand written title tag',
            'meta_description' => 'A hand written description.',
        ]);

        self::assertSame(303, $saved->status());

        $meta = $this->app->container()->get(\CMS\Modules\Seo\Contracts\SeoServiceInterface::class)
            ->metaFor($id, $this->siteId);

        self::assertSame('A hand written title tag', $meta->title);
        self::assertSame('A hand written description.', $meta->description);
    }
}
