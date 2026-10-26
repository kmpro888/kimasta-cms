<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\UserService;
use CMS\Modules\Media\Image\ImageProcessor;
use CMS\Modules\Media\Repository\PdoMediaRepository;
use CMS\Modules\Media\Service\MediaService;
use CMS\Modules\Media\Support\NullMediaUsageChecker;
use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Seo\Repository\PdoSeoRepository;
use CMS\Modules\Seo\Service\BreadcrumbService;
use CMS\Modules\Seo\Service\SeoService;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Repository\PdoSettingsRepository;
use CMS\Modules\Site\Service\SiteService;
use CMS\Modules\Site\Service\SettingsService;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * SEO generation against a real database.
 *
 * The acceptance criteria under test: tags are generated centrally with sensible
 * defaults, overrides win, canonical is single-sourced, and noindexed content is
 * reported as such.
 */
#[CoversClass(SeoService::class)]
#[CoversClass(BreadcrumbService::class)]
final class SeoServiceTest extends ModuleDatabaseTestCase
{
    private SeoServiceInterface $seo;

    private ContentServiceInterface $contents;

    private BreadcrumbServiceInterface $breadcrumbs;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = $this->createSiteRow('main', 'example.com');

        $contentRepository = new PdoContentRepository($this->db);
        $clock = new FrozenClock();
        $registry = new PdoUrlRegistryRepository($this->db);

        $this->contents = new ContentService(
            $contentRepository,
            new SlugService($contentRepository),
            new UrlGenerator($registry),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            $clock,
        );

        $siteService = new SiteService(
            new PdoSiteRepository($this->db),
            new PdoDomainRepository($this->db),
            new DomainMap($this->path('cache/system')),
            new HostNormalizer(),
        );

        $userService = new UserService(new PdoUserRepository($this->db), $clock);

        $mediaService = new MediaService(
            new PdoMediaRepository($this->db),
            new ImageProcessor(),
            new NullMediaUsageChecker(),
            new RecordingEventDispatcher(),
            $clock,
            $this->path('public/uploads'),
        );

        $this->seo = new SeoService(
            new PdoSeoRepository($this->db),
            $this->contents,
            new ContentTypeRegistry(),
            new UrlGenerator($registry),
            $siteService,
            $userService,
            $mediaService,
        );

        $this->breadcrumbs = new BreadcrumbService(
            $this->contents,
            new UrlGenerator($registry),
            $siteService,
        );
    }

    private function makeContent(string $title = 'Hello World', string $status = 'draft'): int
    {
        return (int) $this->contents->create($this->siteId, [
            'title' => $title,
            'author_id' => 1,
            'status' => $status,
            'excerpt' => 'A hand written summary.',
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Body copy here.']]],
        ])->id;
    }

    #[Test]
    public function defaultsAreDerivedFromTheContent(): void
    {
        $contentId = $this->makeContent('Hello World');

        $meta = $this->seo->metaFor($contentId, $this->siteId);

        self::assertSame('Hello World', $meta->title);
        self::assertSame('A hand written summary.', $meta->description);
        self::assertStringContainsString('/blog/hello-world', $meta->canonical);
        self::assertTrue($meta->robotsIndex);
        self::assertTrue($meta->robotsFollow);
    }

    #[Test]
    public function aDescriptionIsDerivedWhenNoExcerptExists(): void
    {
        $contentId = (int) $this->contents->create($this->siteId, [
            'title' => 'No Excerpt',
            'author_id' => 1,
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Derived description text']]],
        ])->id;

        $meta = $this->seo->metaFor($contentId, $this->siteId);

        self::assertSame('Derived description text', $meta->description);
    }

    #[Test]
    public function customSeoOverridesTheDefaults(): void
    {
        $contentId = $this->makeContent();

        $meta = $this->seo->store($contentId, $this->siteId, [
            'meta_title' => 'Custom Title',
            'meta_description' => 'Custom description.',
            'canonical_url' => 'https://example.com/canonical-target',
        ]);

        self::assertSame('Custom Title', $meta->title);
        self::assertSame('Custom description.', $meta->description);
        self::assertSame('https://example.com/canonical-target', $meta->canonical);
    }

    #[Test]
    public function canonicalDefaultsToTheContentUrl(): void
    {
        $contentId = $this->makeContent('Canonical Test');

        self::assertStringEndsWith('/blog/canonical-test', $this->seo->metaFor($contentId, $this->siteId)->canonical);
    }

    #[Test]
    public function noindexIsRecordedAndReported(): void
    {
        $contentId = $this->makeContent();

        $meta = $this->seo->store($contentId, $this->siteId, [
            'robots_index' => false,
            'robots_follow' => false,
        ]);

        self::assertFalse($meta->isIndexable());
        self::assertSame('noindex,nofollow', $meta->robots());
        self::assertFalse($this->seo->isIndexable($contentId, $this->siteId));
    }

    #[Test]
    public function schemaTypeDefaultsByContentType(): void
    {
        $postId = $this->makeContent('A Post');
        $pageId = (int) $this->contents->create($this->siteId, [
            'type' => 'page',
            'title' => 'A Page',
            'author_id' => 1,
        ])->id;

        self::assertSame('Article', $this->seo->metaFor($postId, $this->siteId)->schemaType);
        self::assertSame('WebPage', $this->seo->metaFor($pageId, $this->siteId)->schemaType);
    }

    #[Test]
    public function renderProducesTheExpectedTags(): void
    {
        $contentId = $this->makeContent('Tag Test');
        $html = $this->seo->render($this->seo->metaFor($contentId, $this->siteId));

        self::assertStringContainsString('<title>Tag Test</title>', $html);
        self::assertStringContainsString('<meta name="description"', $html);
        self::assertStringContainsString('<link rel="canonical"', $html);
        self::assertStringContainsString('<meta name="robots" content="index,follow">', $html);
        self::assertStringContainsString('property="og:title"', $html);
        self::assertStringContainsString('name="twitter:card"', $html);
    }

    #[Test]
    public function renderEscapesUserData(): void
    {
        $contentId = (int) $this->contents->create($this->siteId, [
            'title' => 'Title with "quotes" & <tags>',
            'author_id' => 1,
        ])->id;

        $html = $this->seo->render($this->seo->metaFor($contentId, $this->siteId));

        self::assertStringNotContainsString('<tags>', $html);
        self::assertStringContainsString('&lt;tags&gt;', $html);
        self::assertStringContainsString('&quot;quotes&quot;', $html);
    }

    #[Test]
    public function renderSchemaProducesValidJsonLd(): void
    {
        $contentId = $this->makeContent('Schema Test');
        $html = $this->seo->renderSchema($this->seo->metaFor($contentId, $this->siteId));

        self::assertStringContainsString('application/ld+json', $html);

        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $matches);

        self::assertNotEmpty($matches);

        $decoded = json_decode(str_replace('<\/', '</', $matches[1]), true);

        self::assertIsArray($decoded);
        self::assertSame('Article', $decoded['@type']);
        self::assertSame('Schema Test', $decoded['headline']);
    }

    #[Test]
    public function schemaJsonCannotBreakOutOfTheScriptTag(): void
    {
        $contentId = (int) $this->contents->create($this->siteId, [
            'title' => 'Break</script><script>alert(1)</script>',
            'author_id' => 1,
        ])->id;

        $html = $this->seo->renderSchema($this->seo->metaFor($contentId, $this->siteId));

        // Only the wrapper's own closing tag may appear.
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringContainsString('<\/script>', $html);
    }

    #[Test]
    public function breadcrumbsBuildAPostTrail(): void
    {
        $contentId = $this->makeContent('Breadcrumb Post');
        $items = $this->breadcrumbs->forContent($contentId, $this->siteId);

        self::assertCount(3, $items);
        self::assertSame('/blog/breadcrumb-post', $items[2]['url']);
    }

    #[Test]
    public function breadcrumbsBuildAPageTrailWithoutArchive(): void
    {
        $pageId = (int) $this->contents->create($this->siteId, [
            'type' => 'page',
            'title' => 'About',
            'author_id' => 1,
        ])->id;

        $items = $this->breadcrumbs->forContent($pageId, $this->siteId);

        self::assertCount(2, $items);
        self::assertSame('/about', $items[1]['url']);
    }

    #[Test]
    public function breadcrumbsRenderHtmlAndSchema(): void
    {
        $contentId = $this->makeContent('Crumb Render');
        $items = $this->breadcrumbs->forContent($contentId, $this->siteId);

        $html = $this->breadcrumbs->render($items);
        self::assertStringContainsString('aria-label="Breadcrumb"', $html);
        self::assertStringContainsString('aria-current="page"', $html);

        $schema = $this->breadcrumbs->renderSchema($items);
        self::assertStringContainsString('BreadcrumbList', $schema);
    }

    #[Test]
    public function aForeignContentIdIsRejected(): void
    {
        $contentId = $this->makeContent();
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->expectException(\CMS\Modules\Content\Exceptions\ContentNotFoundException::class);

        $this->seo->metaFor($contentId, $otherSite);
    }

    #[Test]
    public function seoRowsAreScopedPerSite(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $contentA = $this->makeContent('Same Title');
        $contentB = (int) $this->contents->create($otherSite, [
            'title' => 'Same Title',
            'author_id' => 1,
        ])->id;

        $this->seo->store($contentA, $this->siteId, ['meta_title' => 'Site One Title']);
        $this->seo->store($contentB, $otherSite, ['meta_title' => 'Site Two Title']);

        self::assertSame('Site One Title', $this->seo->metaFor($contentA, $this->siteId)->title);
        self::assertSame('Site Two Title', $this->seo->metaFor($contentB, $otherSite)->title);
    }
}
