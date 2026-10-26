<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Content;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentQueryService;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Read-side content queries.
 */
#[CoversClass(ContentQueryService::class)]
final class ContentQueryTest extends ModuleDatabaseTestCase
{
    private ContentQueryServiceInterface $query;

    private ContentService $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = new PdoContentRepository($this->db);
        $registry = new PdoUrlRegistryRepository($this->db);
        $clock = new FrozenClock();

        $this->query = new ContentQueryService($repository, $registry);

        $this->writer = new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator($registry),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            $clock,
        );
    }

    #[Test]
    public function paginateReturnsItemsAndMetadata(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        for ($i = 1; $i <= 3; $i++) {
            $this->writer->create($siteId, ['title' => 'Post ' . $i, 'author_id' => 1]);
        }

        $result = $this->query->paginate($siteId, ['limit' => 2, 'page' => 1]);

        self::assertCount(2, $result['items']);
        self::assertSame(3, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(2, $result['per_page']);
        self::assertSame(2, $result['total_pages']);
    }

    #[Test]
    public function paginateSecondPageReturnsTheRemainder(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        for ($i = 1; $i <= 3; $i++) {
            $this->writer->create($siteId, ['title' => 'Post ' . $i, 'author_id' => 1]);
        }

        $result = $this->query->paginate($siteId, ['limit' => 2, 'page' => 2]);

        self::assertCount(1, $result['items']);
    }

    #[Test]
    public function paginateFiltersByType(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->writer->create($siteId, ['type' => 'post', 'title' => 'A post', 'author_id' => 1]);
        $this->writer->create($siteId, ['type' => 'page', 'title' => 'A page', 'author_id' => 1]);

        $result = $this->query->paginate($siteId, ['type' => 'page']);

        self::assertCount(1, $result['items']);
        self::assertSame('page', $result['items'][0]->type);
    }

    #[Test]
    public function paginateFiltersByStatus(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $published = $this->writer->create($siteId, ['title' => 'Published', 'author_id' => 1]);
        $this->writer->create($siteId, ['title' => 'Draft', 'author_id' => 1]);
        $this->writer->setStatus((int) $published->id, $siteId, 'published');

        $result = $this->query->paginate($siteId, ['status' => 'published']);

        self::assertCount(1, $result['items']);
        self::assertSame('Published', $result['items'][0]->title);
    }

    #[Test]
    public function paginateExcludesTrashWhenAsked(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $keep = $this->writer->create($siteId, ['title' => 'Keep', 'author_id' => 1]);
        $drop = $this->writer->create($siteId, ['title' => 'Drop', 'author_id' => 1]);
        $this->writer->trash((int) $drop->id, $siteId);

        $result = $this->query->paginate($siteId, ['exclude_trash' => true]);

        self::assertCount(1, $result['items']);
        self::assertSame($keep->id, $result['items'][0]->id);
    }

    #[Test]
    public function perPageIsClamped(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $result = $this->query->paginate($siteId, ['limit' => 100000]);

        self::assertSame(100, $result['per_page']);
    }

    #[Test]
    public function publishedReturnsOnlyPubliclyVisiblePosts(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $live = $this->writer->create($siteId, ['title' => 'Live', 'author_id' => 1]);
        $this->writer->create($siteId, ['title' => 'Draft', 'author_id' => 1]);
        $this->writer->setStatus((int) $live->id, $siteId, 'published');

        $items = $this->query->published($siteId);

        self::assertCount(1, $items);
        self::assertSame('Live', $items[0]->title);
    }

    #[Test]
    public function summariesDoNotCarryTheBlockPayload(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->writer->create($siteId, [
            'title' => 'Heavy',
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => str_repeat('body ', 500)]]],
        ]);

        // paginate() covers drafts as well as published rows.
        $result = $this->query->paginate($siteId, ['limit' => 10]);
        $item = $result['items'][0];

        // The projection type simply has no block fields, so a heavy column
        // cannot leak into a listing.
        self::assertFalse(property_exists($item, 'contentJson'));
        self::assertFalse(property_exists($item, 'contentHtml'));
        self::assertFalse(property_exists($item, 'contentPlain'));
    }

    #[Test]
    public function findByUrlResolvesAPermalink(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->writer->create($siteId, ['title' => 'Resolve Me', 'author_id' => 1]);

        $found = $this->query->findByUrl($siteId, '/blog/resolve-me');

        self::assertSame($content->id, $found->id);
    }

    #[Test]
    public function findByUrlToleratesAQueryStringAndTrailingSlash(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->writer->create($siteId, ['title' => 'Messy Url', 'author_id' => 1]);

        self::assertNotNull($this->query->findByUrl($siteId, '/blog/messy-url/?utm=x'));
    }

    #[Test]
    public function findByUrlThrowsForAnUnknownPath(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(ContentNotFoundException::class);

        $this->query->findByUrl($siteId, '/blog/never-existed');
    }

    #[Test]
    public function findByUrlThrowsForANonContentEntity(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        (new PdoUrlRegistryRepository($this->db))->put($siteId, '/category/news', 'term', 5);

        $this->expectException(ContentNotFoundException::class);

        $this->query->findByUrl($siteId, '/category/news');
    }

    #[Test]
    public function aStaleRegistryRowIsTreatedAsNotFound(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        (new PdoUrlRegistryRepository($this->db))->put($siteId, '/blog/ghost', 'content', 999);

        $this->expectException(ContentNotFoundException::class);

        $this->query->findByUrl($siteId, '/blog/ghost');
    }

    #[Test]
    public function aTrashedPostStopsResolving(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->writer->create($siteId, ['title' => 'Soon Gone', 'author_id' => 1]);
        $this->writer->trash((int) $content->id, $siteId);

        $this->expectException(ContentNotFoundException::class);

        $this->query->findByUrl($siteId, '/blog/soon-gone');
    }

    #[Test]
    public function emptySiteReturnsNoItems(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $result = $this->query->paginate($siteId);

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['total_pages']);
    }
}
