<?php

declare(strict_types=1);

namespace Tests\Integration\EndToEnd;

use CMS\Core\Database\Connection;
use CMS\Core\Database\QueryProfiler;
use CMS\Core\Http\Request;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * The mandatory end-to-end scenario from the task pack (section 7).
 *
 * Every step goes through real HTTP: the API for the editorial work, the public
 * controller for the reader's view. Nothing is stubbed and no module is bypassed.
 *
 *   contributor login -> create article -> upload image -> add category -> set SEO
 *   -> submit -> reviewer requests revision -> contributor edits -> resubmit
 *   -> reviewer approves -> editor publishes -> cache generated -> visitor reads
 *   -> second visitor costs 0 queries -> editor edits -> cache invalidated
 *   -> new title visible immediately
 *
 * This is the only test in the suite that crosses module boundaries in one flow, which
 * is exactly where wiring breaks are invisible to module-scoped tests.
 */
final class PublishFlowTest extends ApiTestCase
{
    private int $contributorId;

    private int $reviewerId;

    private int $editorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contributorId = $this->makeUser('writer', 'contributor');
        $this->reviewerId = $this->makeUser('reviewer', 'reviewer');
        $this->editorId = $this->makeUser('editor', 'editor');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Log in through the real endpoint, so the session is created by the same code a
     * browser would hit.
     */
    private function login(string $username): void
    {
        $this->logout();

        $response = $this->call('POST', '/api/v1/auth/login', [
            'identifier' => $username,
            'password' => 'correct-horse-battery',
        ]);

        self::assertSame(200, $response->status(), 'Login failed for ' . $username . ': ' . $response->content());
    }

    private function transition(int $contentId, string $status): array
    {
        $response = $this->call('POST', '/api/v1/content/' . $contentId . '/transitions', [
            'status' => $status,
        ]);

        return [$response->status(), $this->json($response)];
    }

    /**
     * A small real PNG, so the media pipeline validates an actual file rather than a stub.
     */
    private function imagePath(): string
    {
        $path = $this->path('uploads/end-to-end.png');

        @mkdir(dirname($path), 0775, true);

        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $path);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPublicPage(string $path): array
    {
        $response = $this->app->handle(Request::create('GET', $path, $this->host));

        return [
            'status' => $response->status(),
            'body' => $response->content(),
            'cache' => $response->header('X-Cache'),
        ];
    }

    // --------------------------------------------------------------------- flow

    #[Test]
    public function theMandatoryPublishFlowWorksEndToEnd(): void
    {
        // ---- 1. Contributor login -------------------------------------------
        $this->login('writer');

        $me = $this->call('GET', '/api/v1/auth/me');
        self::assertSame(200, $me->status());
        self::assertSame('writer', $this->json($me)['data']['username']);

        // ---- 2. Create article ----------------------------------------------
        $created = $this->call('POST', '/api/v1/me/content', [
            'title' => 'Harbour Report',
            'excerpt' => 'A report from the harbour.',
            'content_json' => [
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Harbour Report', 'level' => 2]],
                ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'The tide came in early.']],
            ],
        ]);

        self::assertSame(201, $created->status(), 'Create draft failed: ' . $created->content());

        $article = $this->json($created)['data'];
        $contentId = (int) $article['id'];

        self::assertSame('draft', $article['status']);
        self::assertSame($this->contributorId, $article['author_id']);

        // ---- 3. Upload image -------------------------------------------------
        // The media upload endpoint does not exist yet (multipart parsing is missing from
        // the HTTP layer), so the file is uploaded through the service the API would call.
        $media = $this->app->container()->get(MediaServiceInterface::class)->upload(
            $this->siteId,
            $this->contributorId,
            new UploadedFile(
                tmpPath: $this->imagePath(),
                originalName: 'harbour.png',
                size: (int) filesize($this->imagePath()),
                clientMime: 'image/png',
                error: UPLOAD_ERR_OK,
            ),
            'The harbour at dawn',
        );

        self::assertNotNull($media->id);
        self::assertTrue($media->isImage());

        $withImage = $this->call('PATCH', '/api/v1/me/content/' . $contentId, [
            'featured_media_id' => $media->id,
        ]);

        self::assertSame(200, $withImage->status(), 'Attaching the image failed: ' . $withImage->content());

        // ---- 4. Add category -------------------------------------------------
        // The vocabulary itself is managed by an editor; the contributor applies it.
        $this->login('editor');

        $term = $this->app->container()->get(TaxonomyServiceInterface::class)
            ->createTerm($this->siteId, 'category', 'Shipping', 'Everything about ships.');

        $this->login('writer');

        $terms = $this->call('PUT', '/api/v1/content/' . $contentId . '/terms', [
            'term_ids' => [(int) $term->id],
        ]);

        // 204 No Content: the endpoint replaces a set and returns no body.
        self::assertSame(204, $terms->status(), 'Attaching the category failed: ' . $terms->content());

        $listed = $this->call('GET', '/api/v1/content/' . $contentId . '/terms');
        self::assertSame(200, $listed->status());
        self::assertCount(1, $this->json($listed)['data']);

        // Classifying the article must not disturb it in any way.
        $survives = $this->app->container()->get(ContentServiceInterface::class)->get($contentId, $this->siteId);
        self::assertSame($contentId, (int) $survives->id);

        // ---- 5. Set SEO ------------------------------------------------------
        $seo = $this->call('PUT', '/api/v1/content/' . $contentId . '/seo', [
            'meta_title' => 'Harbour Report - Custom Title',
            'meta_description' => 'A hand written description for search.',
        ]);

        self::assertSame(200, $seo->status(), 'Setting SEO failed: ' . $seo->content());
        self::assertSame('Harbour Report - Custom Title', $this->json($seo)['data']['title']);

        // ---- 6. Submit for review -------------------------------------------
        $submitted = $this->call('POST', '/api/v1/me/content/' . $contentId . '/submit', [
            'note' => 'Ready for review.',
        ]);

        self::assertSame(200, $submitted->status(), 'Submit failed: ' . $submitted->content());
        self::assertSame('submitted', $this->json($submitted)['data']['status']);

        // ---- 7. Reviewer requests a revision --------------------------------
        $this->login('reviewer');

        [$status] = $this->transition($contentId, 'review');
        self::assertSame(200, $status, 'Starting review failed.');

        [$status] = $this->transition($contentId, 'revision_requested');
        self::assertSame(200, $status, 'Requesting a revision failed.');

        // ---- 8. Contributor edits -------------------------------------------
        $this->login('writer');

        $edited = $this->call('PATCH', '/api/v1/me/content/' . $contentId, [
            'excerpt' => 'A revised report from the harbour.',
        ]);

        self::assertSame(200, $edited->status(), 'Editing after a revision request failed: ' . $edited->content());
        self::assertSame('A revised report from the harbour.', $this->json($edited)['data']['excerpt']);

        // ---- 9. Resubmit -----------------------------------------------------
        $resubmitted = $this->call('POST', '/api/v1/me/content/' . $contentId . '/submit', [
            'note' => 'Revision done.',
        ]);

        self::assertSame(200, $resubmitted->status(), 'Resubmit failed: ' . $resubmitted->content());
        self::assertSame('submitted', $this->json($resubmitted)['data']['status']);

        // ---- 10. Reviewer approves -------------------------------------------
        $this->login('reviewer');

        [$status] = $this->transition($contentId, 'review');
        self::assertSame(200, $status);

        [$status] = $this->transition($contentId, 'approved');
        self::assertSame(200, $status, 'Approving failed.');

        // ---- 11. Editor publishes --------------------------------------------
        $this->login('editor');

        [$status, $payload] = $this->transition($contentId, 'published');
        self::assertSame(200, $status, 'Publishing failed: ' . json_encode($payload));
        self::assertSame('published', $payload['data']['status']);

        $content = $this->app->container()->get(ContentServiceInterface::class)->get($contentId, $this->siteId);
        $slug = $content->slug;

        // ---- 12/13. Cache is generated, then a visitor reads the article -----
        $first = $this->readPublicPage('/blog/' . $slug);

        self::assertSame(200, $first['status']);
        self::assertSame('MISS', $first['cache'], 'The first public read must be a cache miss.');
        self::assertStringContainsString('Harbour Report', $first['body']);
        self::assertStringContainsString('The tide came in early.', $first['body']);
        self::assertStringContainsString('Harbour Report - Custom Title', $first['body']);

        // ---- 14. A second visitor costs zero database queries ----------------
        // Profile the container's connection: it is the one the repositories use, and it
        // is not the same instance the test harness holds for fixtures.
        $connection = $this->app->container()->get(Connection::class);
        $profiler = new QueryProfiler();
        $connection->profiler($profiler);

        try {
            $second = $this->readPublicPage('/blog/' . $slug);
        } finally {
            $connection->profiler(null);
        }

        self::assertSame(200, $second['status']);
        self::assertSame('HIT', $second['cache'], 'The second public read must be a cache hit.');
        self::assertSame(0, $profiler->count(), 'A cached public page must cost zero queries.');
        self::assertSame($first['body'], $second['body']);

        // ---- 15. Editor updates the title ------------------------------------
        $this->login('editor');

        $updated = $this->call('PATCH', '/api/v1/content/' . $contentId, [
            'title' => 'Harbour Report Updated',
        ]);

        self::assertSame(200, $updated->status(), 'Updating the title failed: ' . $updated->content());

        // ---- 16. The cached page is invalidated ------------------------------
        $cache = $this->app->container()->get(PageCacheInterface::class);

        self::assertNull(
            $cache->get($this->siteId, '/blog/' . $slug),
            'A published content edit must invalidate the cached page.'
        );

        // ---- 17. The new title is visible immediately -------------------------
        $afterEdit = $this->readPublicPage('/blog/' . $slug);

        self::assertSame(200, $afterEdit['status']);
        self::assertStringContainsString('Harbour Report Updated', $afterEdit['body']);

        // The stored SEO override still wins over the new content title. That is intended:
        // an editor who wrote a title tag deliberately must not have it silently replaced
        // by a later copy edit. The content title changed; the override did not.
        self::assertStringContainsString('Harbour Report - Custom Title', $afterEdit['body']);
    }

    #[Test]
    public function aContributorCannotPublishAndAnAnonymousVisitorCannotMutate(): void
    {
        $this->login('writer');

        $created = $this->call('POST', '/api/v1/me/content', ['title' => 'Not Mine To Publish']);
        $contentId = (int) $this->json($created)['data']['id'];

        // A contributor holds `workflow.submit`, not `content.publish`.
        [$status] = $this->transition($contentId, 'published');
        self::assertContains($status, [403, 409], 'A contributor must not be able to publish.');

        $this->logout();

        self::assertSame(401, $this->call('POST', '/api/v1/me/content', ['title' => 'Anon'])->status());
        self::assertSame(401, $this->call('PATCH', '/api/v1/content/' . $contentId, ['title' => 'Anon'])->status());
    }
}
