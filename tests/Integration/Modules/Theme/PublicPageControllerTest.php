<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Database\QueryProfiler;
use CMS\Core\Http\Request;
use CMS\Modules\Cache\Contracts\CacheInvalidationServiceInterface;
use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Cache\Page\PageCache;
use CMS\Modules\Cache\Service\CacheInvalidationService;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentQueryService;
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
use CMS\Modules\Seo\Repository\PdoRedirectRepository;
use CMS\Modules\Seo\Repository\PdoSeoRepository;
use CMS\Modules\Seo\Service\BreadcrumbService;
use CMS\Modules\Seo\Service\RedirectService;
use CMS\Modules\Seo\Service\SeoService;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Service\SiteResolver;
use CMS\Modules\Site\Service\SiteService;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use CMS\Modules\Taxonomy\Repository\PdoTaxonomyRepository;
use CMS\Modules\Taxonomy\Repository\PdoTermRepository;
use CMS\Modules\Taxonomy\Service\TaxonomyRegistry;
use CMS\Modules\Taxonomy\Service\TaxonomyService;
use CMS\Modules\Theme\Http\PublicPageController;
use CMS\Modules\Theme\Rendering\PageViewModelFactory;
use CMS\Modules\Theme\Service\TemplateResolver;
use CMS\Modules\Theme\Service\ThemeRegistry;
use CMS\Modules\Theme\Service\ViewRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * End-to-end public rendering over the real stack.
 *
 * This is the test that proves the platform actually serves a page: site
 * resolution, page cache, URL registry, content, SEO, taxonomy, theme and
 * invalidation all wired together and exercised through the controller.
 */
#[CoversClass(PublicPageController::class)]
#[CoversClass(PageViewModelFactory::class)]
final class PublicPageControllerTest extends ModuleDatabaseTestCase
{
    private PublicPageController $controller;

    private ContentServiceInterface $contents;

    private PageCache $pages;

    private CacheInvalidationService $invalidation;

    private RedirectService $redirects;

    private SiteResolver $resolver;

    private int $siteId;

    private string $host = 'example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = $this->createSiteRow('main', $this->host);

        $clock = new FrozenClock();
        $cacheRoot = $this->path('cache');
        $themesRoot = CMS_TEST_ROOT . '/themes';

        // Repositories
        $contentRepository = new PdoContentRepository($this->db);
        $urlRegistry = new PdoUrlRegistryRepository($this->db);
        $siteRepository = new PdoSiteRepository($this->db);
        $domainRepository = new PdoDomainRepository($this->db);

        // Site layer
        $domainMap = new DomainMap($cacheRoot . '/system');
        $siteService = new SiteService($siteRepository, $domainRepository, $domainMap, new HostNormalizer());
        $this->resolver = new SiteResolver(
            new HostNormalizer(),
            $domainMap,
            $domainRepository,
            $siteRepository,
        );

        // Warm the compiled domain map so the fast path truly needs no database.
        $siteService->regenerateDomainMap();

        // Content
        $this->contents = new ContentService(
            $contentRepository,
            new SlugService($contentRepository),
            new UrlGenerator($urlRegistry),
            new PlainBlockCompiler(),
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            $clock,
        );

        $query = new ContentQueryService($contentRepository, $urlRegistry);

        // Supporting services
        $userService = new UserService(new PdoUserRepository($this->db), $clock);

        $mediaService = new MediaService(
            new PdoMediaRepository($this->db),
            new ImageProcessor(),
            new NullMediaUsageChecker(),
            new RecordingEventDispatcher(),
            $clock,
            $this->path('public/uploads'),
        );

        $taxonomy = new TaxonomyService(
            new TaxonomyRegistry(),
            new PdoTaxonomyRepository($this->db),
            new PdoTermRepository($this->db),
            new RecordingEventDispatcher(),
            $clock,
        );

        $urlGenerator = new UrlGenerator($urlRegistry);

        $seo = new SeoService(
            new PdoSeoRepository($this->db),
            $this->contents,
            new ContentTypeRegistry(),
            $urlGenerator,
            $siteService,
            $userService,
            $mediaService,
        );

        $breadcrumbs = new BreadcrumbService($this->contents, $urlGenerator, $siteService);

        // Theme
        $themes = new ThemeRegistry(
            $themesRoot,
            $siteRepository,
            new RecordingEventDispatcher(),
            $clock,
        );

        $this->pages = new PageCache($cacheRoot . '/pages');

        $events = new RecordingEventDispatcher();
        $this->invalidation = new CacheInvalidationService($this->pages, $events);

        // The counter is part of the redirect service now; these tests never exercise counting.
        $this->redirects = new RedirectService(
            new PdoRedirectRepository($this->db),
            new \CMS\Modules\Seo\Support\RedirectHitCounter($this->tempDir . '/redirect-hits')
        );

        $this->controller = new PublicPageController(
            $this->resolver,
            $this->pages,
            $query,
            $this->redirects,
            $themes,
            new TemplateResolver($themes),
            new ViewRenderer($themes),
            new PageViewModelFactory(
                $query,
                $urlGenerator,
                $seo,
                $breadcrumbs,
                $taxonomy,
                $mediaService,
            ),
            $taxonomy,
            new ContentTypeRegistry(),
        );
    }

    private function publishArticle(string $title = 'Hello World', string $status = 'published'): int
    {
        return (int) $this->contents->create($this->siteId, [
            'title' => $title,
            'author_id' => 1,
            'status' => $status,
            'excerpt' => 'An article summary.',
            'content_json' => [
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => $title, 'level' => 1]],
                ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Article body copy.']],
            ],
        ])->id;
    }

    private function get(string $path, string $method = 'GET'): \CMS\Core\Http\Response
    {
        return $this->controller->handle(Request::create($method, $path, $this->host));
    }

    #[Test]
    public function aPublishedArticleRendersHtml(): void
    {
        $this->publishArticle('Rendered Article');

        $response = $this->get('/blog/rendered-article');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('Rendered Article', $response->content());
        self::assertStringContainsString('Article body copy.', $response->content());
    }

    #[Test]
    public function seoTagsAreRenderedCentrally(): void
    {
        $this->publishArticle('Seo Article');

        $html = $this->get('/blog/seo-article')->content();

        self::assertStringContainsString('<title>Seo Article</title>', $html);
        self::assertStringContainsString('rel="canonical"', $html);
        self::assertStringContainsString('name="robots"', $html);
        self::assertStringContainsString('application/ld+json', $html);
    }

    #[Test]
    public function theFirstRequestIsAMissAndTheSecondIsAHit(): void
    {
        $this->publishArticle('Cache Me');

        $first = $this->get('/blog/cache-me');
        self::assertSame('MISS', $first->header('X-Cache'));

        $second = $this->get('/blog/cache-me');
        self::assertSame('HIT', $second->header('X-Cache'));
        self::assertSame($first->content(), $second->content());
    }

    #[Test]
    public function aCacheHitCostsZeroDatabaseQueries(): void
    {
        $this->publishArticle('Zero Query');
        $this->get('/blog/zero-query'); // warm the cache

        $profiler = new QueryProfiler();
        $this->db->profiler($profiler);

        try {
            $response = $this->get('/blog/zero-query');
        } finally {
            $this->db->profiler(null);
        }

        self::assertSame('HIT', $response->header('X-Cache'));
        // The product promise: domain map (file) + page cache (file), no SQL.
        self::assertSame(0, $profiler->count());
    }

    #[Test]
    public function theCachedPageDeclaresTheDependenciesInvalidationNeeds(): void
    {
        $contentId = $this->publishArticle('Dependency Article');

        $this->get('/blog/dependency-article');

        $index = $this->pages->loadIndex($this->siteId);

        self::assertArrayHasKey('content:' . $contentId, $index);
        self::assertArrayHasKey('archive:' . $this->siteId, $index);
        self::assertArrayHasKey('theme:default', $index);
        self::assertArrayHasKey('settings:' . $this->siteId, $index);
        self::assertArrayHasKey('author:1', $index);
    }

    #[Test]
    public function aContentEditImmediatelyInvalidatesTheCachedPage(): void
    {
        $contentId = $this->publishArticle('Editable Article');

        $this->get('/blog/editable-article');
        self::assertNotNull($this->pages->get($this->siteId, '/blog/editable-article'));

        // This is exactly what ContentService emits on an update.
        $this->invalidation->invalidateForEvent('content.updated', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]);

        self::assertNull($this->pages->get($this->siteId, '/blog/editable-article'));

        // And the next request renders the new state rather than the stale one.
        $this->contents->update($contentId, $this->siteId, ['title' => 'Edited Article']);

        $response = $this->get('/blog/editable-article');

        self::assertSame('MISS', $response->header('X-Cache'));
        self::assertStringContainsString('<title>Edited Article</title>', $response->content());
        self::assertStringContainsString('<h1>Edited Article</h1>', $response->content());
    }

    #[Test]
    public function anUnknownPathIsA404(): void
    {
        $response = $this->get('/blog/does-not-exist');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Not found', $response->content());
    }

    #[Test]
    public function aDraftIsNotServedAnonymously(): void
    {
        $this->publishArticle('Secret Draft', 'draft');

        $response = $this->get('/blog/secret-draft');

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString('Article body copy.', $response->content());
    }

    #[Test]
    public function anUnknownHostIsA404AndNeverFallsBackToADefaultSite(): void
    {
        $this->publishArticle('Host Guard');

        $response = $this->controller->handle(
            Request::create('GET', '/blog/host-guard', 'unknown.test')
        );

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString('Host Guard', $response->content());
    }

    #[Test]
    public function aRedirectIsResolvedBeforeContent(): void
    {
        $this->redirects->store($this->siteId, '/blog/old-path', '/blog/new-path', 301);

        $response = $this->get('/blog/old-path');

        self::assertSame(301, $response->status());
        self::assertSame('/blog/new-path', $response->header('Location'));
    }

    #[Test]
    public function aRedirectOutranksA404(): void
    {
        $this->redirects->store($this->siteId, '/gone', '/blog/hello', 302);

        $response = $this->get('/gone');

        self::assertSame(302, $response->status());
    }

    #[Test]
    public function anotherSitesCacheIsNeverUsed(): void
    {
        $otherHost = 'other.example';
        $otherSite = $this->createSiteRow('other', $otherHost);

        // Same path on both tenants, different content.
        $this->publishArticle('Main Article');
        $this->contents->create($otherSite, [
            'title' => 'Main Article',
            'author_id' => 1,
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Other tenant body.']]],
        ]);

        $main = $this->get('/blog/main-article')->content();

        $other = $this->controller->handle(
            Request::create('GET', '/blog/main-article', $otherHost)
        )->content();

        self::assertStringContainsString('Article body copy.', $main);
        self::assertStringContainsString('Other tenant body.', $other);
        self::assertStringNotContainsString('Other tenant body.', $main);
    }

    #[Test]
    public function aHeadRequestReturnsNoBody(): void
    {
        $this->publishArticle('Head Request');

        // The router strips the body for HEAD; the controller itself may render it.
        $response = $this->get('/blog/head-request', 'HEAD');

        self::assertSame(200, $response->status());
    }

    #[Test]
    public function aPreviewRequestBypassesTheCache(): void
    {
        $this->publishArticle('Preview Article');
        $this->get('/blog/preview-article'); // warm

        $response = $this->controller->handle(
            Request::create('GET', '/blog/preview-article', $this->host, ['preview' => '1'])
        );

        // A preview must never be served the cached anonymous page.
        self::assertSame('MISS', $response->header('X-Cache'));
    }

    #[Test]
    public function theRenderedPageShowsItsTerms(): void
    {
        $contentId = $this->publishArticle('Tagged Article');

        // Attach a term through the taxonomy service the controller uses.
        $taxonomy = new TaxonomyService(
            new TaxonomyRegistry(),
            new PdoTaxonomyRepository($this->db),
            new PdoTermRepository($this->db),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $term = $taxonomy->createTerm($this->siteId, 'tag', 'Announcement');
        $taxonomy->attach($contentId, (int) $term->id, $this->siteId);

        $html = $this->get('/blog/tagged-article')->content();

        self::assertStringContainsString('Announcement', $html);
    }

    #[Test]
    public function theHomePageRendersAListing(): void
    {
        $this->publishArticle('First Listing Post');
        $this->publishArticle('Second Listing Post');

        $response = $this->get('/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('First Listing Post', $response->content());
        self::assertStringContainsString('Second Listing Post', $response->content());
    }

    #[Test]
    public function aTypeArchiveRendersUnderItsSlug(): void
    {
        $this->publishArticle('Archived Post');

        $response = $this->get('/blog');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Archived Post', $response->content());
    }

    #[Test]
    public function theHomeListingIsCachedWithTheArchiveDependency(): void
    {
        $this->publishArticle('Cache The Home');

        $first = $this->get('/');
        self::assertSame('MISS', $first->header('X-Cache'));

        $second = $this->get('/');
        self::assertSame('HIT', $second->header('X-Cache'));

        $index = $this->pages->loadIndex($this->siteId);
        self::assertArrayHasKey('archive:' . $this->siteId, $index);
    }

    #[Test]
    public function aContentChangeInvalidatesTheCachedListing(): void
    {
        $contentId = $this->publishArticle('Listed Post');

        $this->get('/');
        self::assertNotNull($this->pages->get($this->siteId, '/'));

        $this->invalidation->invalidateForEvent('content.published', [
            'id' => $contentId,
            'site_id' => $this->siteId,
        ]);

        self::assertNull($this->pages->get($this->siteId, '/'));
    }

    #[Test]
    public function aTaxonomyArchiveListsTheTermsContent(): void
    {
        $contentId = $this->publishArticle('Term Archive Post');

        $taxonomy = new TaxonomyService(
            new TaxonomyRegistry(),
            new PdoTaxonomyRepository($this->db),
            new PdoTermRepository($this->db),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $term = $taxonomy->createTerm($this->siteId, 'tag', 'Featured');
        $taxonomy->attach($contentId, (int) $term->id, $this->siteId);

        $response = $this->get('/term/featured');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Term Archive Post', $response->content());
        self::assertStringContainsString('Featured', $response->content());
    }

    #[Test]
    public function anUnknownTermIsA404(): void
    {
        $response = $this->get('/term/never-existed');

        self::assertSame(404, $response->status());
    }

    #[Test]
    public function aTaxonomyArchiveExcludesContentFromAnotherTerm(): void
    {
        $featured = $this->publishArticle('In Featured');
        $other = $this->publishArticle('Not In Featured');

        $taxonomy = new TaxonomyService(
            new TaxonomyRegistry(),
            new PdoTaxonomyRepository($this->db),
            new PdoTermRepository($this->db),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );

        $term = $taxonomy->createTerm($this->siteId, 'tag', 'Only');
        $taxonomy->attach($featured, (int) $term->id, $this->siteId);

        $html = $this->get('/term/only')->content();

        self::assertStringContainsString('In Featured', $html);
        self::assertStringNotContainsString('Not In Featured', $html);
    }

    #[Test]
    public function anAuthorArchiveListsThatAuthorsContent(): void
    {
        $this->publishArticle('By This Author');

        $response = $this->get('/author/1');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('By This Author', $response->content());
    }

    #[Test]
    public function searchFindsContentByTitle(): void
    {
        $this->publishArticle('Distinctive Searchable Title');

        $response = $this->controller->handle(
            Request::create('GET', '/search', $this->host, ['q' => 'Distinctive'])
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Distinctive Searchable Title', $response->content());
    }

    #[Test]
    public function searchIsNeverCached(): void
    {
        $this->publishArticle('Searchable Again');

        $first = $this->controller->handle(
            Request::create('GET', '/search', $this->host, ['q' => 'Searchable'])
        );

        self::assertSame('BYPASS', $first->header('X-Cache'));

        // A different query must not be served the first query's result.
        $second = $this->controller->handle(
            Request::create('GET', '/search', $this->host, ['q' => 'Nothing'])
        );

        self::assertStringNotContainsString('Searchable Again', $second->content());
    }

    #[Test]
    public function anEmptySearchQueryIsA404(): void
    {
        $response = $this->get('/search');

        self::assertSame(404, $response->status());
    }

    #[Test]
    public function aDeeperListingPageBypassesTheCache(): void
    {
        // `/blog?page=2` shares the cache key with `/blog`, so it must not be
        // cached under that key.
        for ($i = 1; $i <= 12; $i++) {
            $this->publishArticle('Listing Post ' . $i);
        }

        $this->get('/blog');

        $second = $this->controller->handle(
            Request::create('GET', '/blog', $this->host, ['page' => '2'])
        );

        self::assertSame('BYPASS', $second->header('X-Cache'));
        self::assertSame(200, $second->status());
    }

    #[Test]
    public function aDraftIsExcludedFromListings(): void
    {
        $this->publishArticle('Visible In Listing');
        $this->publishArticle('Hidden Draft', 'draft');

        $html = $this->get('/')->content();

        self::assertStringContainsString('Visible In Listing', $html);
        self::assertStringNotContainsString('Hidden Draft', $html);
    }

    #[Test]
    public function anUnregisteredArchiveSegmentIsA404(): void
    {
        $response = $this->get('/nothing-here');

        self::assertSame(404, $response->status());
    }
}
