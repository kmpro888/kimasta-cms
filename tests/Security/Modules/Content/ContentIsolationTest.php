<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Content;

use CMS\Modules\Content\Exceptions\ContentNotFoundException;
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
 * Cross-tenant content isolation.
 *
 * Acceptance criterion from the task pack: "Site A cannot access Site B
 * content." Every negative case must behave exactly like a nonexistent row, so a
 * caller cannot probe another tenant for existence.
 */
final class ContentIsolationTest extends ModuleDatabaseTestCase
{
    private ContentService $writer;

    private ContentQueryService $query;

    private int $siteA;

    private int $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = new PdoContentRepository($this->db);
        $registry = new PdoUrlRegistryRepository($this->db);

        $this->writer = new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator($registry),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $this->query = new ContentQueryService($repository, $registry);

        $this->siteA = $this->createSiteRow('alpha', 'alpha.example');
        $this->siteB = $this->createSiteRow('beta', 'beta.example');
    }

    #[Test]
    public function contentCannotBeReadFromAnotherSite(): void
    {
        $content = $this->writer->create($this->siteA, ['title' => 'Alpha Secret', 'author_id' => 1]);

        $this->expectException(ContentNotFoundException::class);

        $this->writer->get((int) $content->id, $this->siteB);
    }

    #[Test]
    public function contentCannotBeUpdatedFromAnotherSite(): void
    {
        $content = $this->writer->create($this->siteA, ['title' => 'Alpha Secret', 'author_id' => 1]);

        try {
            $this->writer->update((int) $content->id, $this->siteB, ['title' => 'Hijacked']);
            self::fail('Cross-site update was allowed.');
        } catch (ContentNotFoundException) {
            // expected
        }

        $reloaded = $this->writer->get((int) $content->id, $this->siteA);
        self::assertSame('Alpha Secret', $reloaded->title);
    }

    #[Test]
    public function contentCannotBeTrashedFromAnotherSite(): void
    {
        $content = $this->writer->create($this->siteA, ['title' => 'Alpha Secret', 'author_id' => 1]);

        self::assertFalse($this->writer->trash((int) $content->id, $this->siteB));
        self::assertSame('draft', $this->writer->get((int) $content->id, $this->siteA)->status);
    }

    #[Test]
    public function contentCannotBePurgedFromAnotherSite(): void
    {
        $content = $this->writer->create($this->siteA, ['title' => 'Alpha Secret', 'author_id' => 1]);
        $this->writer->trash((int) $content->id, $this->siteA);

        self::assertFalse($this->writer->purge((int) $content->id, $this->siteB));
        self::assertNotNull($this->writer->get((int) $content->id, $this->siteA));
    }

    #[Test]
    public function listingIsScopedToTheOwningSite(): void
    {
        $this->writer->create($this->siteA, ['title' => 'Alpha Post', 'author_id' => 1]);
        $this->writer->create($this->siteB, ['title' => 'Beta Post', 'author_id' => 1]);

        $alpha = $this->query->paginate($this->siteA);
        $beta = $this->query->paginate($this->siteB);

        self::assertCount(1, $alpha['items']);
        self::assertSame('Alpha Post', $alpha['items'][0]->title);

        self::assertCount(1, $beta['items']);
        self::assertSame('Beta Post', $beta['items'][0]->title);
    }

    #[Test]
    public function theSameSlugMayExistOnTwoSitesWithoutColliding(): void
    {
        $alpha = $this->writer->create($this->siteA, ['title' => 'Shared Title', 'author_id' => 1]);
        $beta = $this->writer->create($this->siteB, ['title' => 'Shared Title', 'author_id' => 1]);

        self::assertSame('shared-title', $alpha->slug);
        self::assertSame('shared-title', $beta->slug);
        self::assertNotSame($alpha->id, $beta->id);
    }

    #[Test]
    public function aPermalinkFromOneSiteDoesNotResolveOnAnother(): void
    {
        $this->writer->create($this->siteA, ['title' => 'Alpha Secret', 'author_id' => 1]);

        // Same path, different tenant: must be a clean not-found.
        self::assertNotNull($this->query->findByUrl($this->siteA, '/blog/alpha-secret'));

        $this->expectException(ContentNotFoundException::class);

        $this->query->findByUrl($this->siteB, '/blog/alpha-secret');
    }

    #[Test]
    public function theUrlRegistryIsPartitionedBySite(): void
    {
        $alpha = $this->writer->create($this->siteA, ['title' => 'Same Path', 'author_id' => 1]);
        $beta = $this->writer->create($this->siteB, ['title' => 'Same Path', 'author_id' => 1]);

        $registry = new PdoUrlRegistryRepository($this->db);

        self::assertSame($alpha->id, $registry->resolve($this->siteA, '/blog/same-path')['entity_id']);
        self::assertSame($beta->id, $registry->resolve($this->siteB, '/blog/same-path')['entity_id']);
    }

    #[Test]
    public function storedHtmlNeverContainsRawScriptTags(): void
    {
        $content = $this->writer->create($this->siteA, [
            'title' => 'XSS Attempt',
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>']]],
        ]);

        self::assertStringNotContainsString('<script>', (string) $content->contentHtml);
        self::assertStringContainsString('&lt;script&gt;', (string) $content->contentHtml);
    }

    #[Test]
    public function deletingASiteCascadesItsContentAndUrls(): void
    {
        $content = $this->writer->create($this->siteA, ['title' => 'Doomed', 'author_id' => 1]);

        $this->db->statement('DELETE FROM sites WHERE id = :id', ['id' => $this->siteA]);

        self::assertNull($this->db->scalar(
            'SELECT id FROM contents WHERE id = :id',
            ['id' => (int) $content->id]
        ));

        self::assertNull($this->db->scalar(
            'SELECT path FROM url_registry WHERE site_id = :site_id',
            ['site_id' => $this->siteA]
        ));
    }
}
