<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
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
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Seo\Repository\PdoSeoRepository;
use CMS\Modules\Seo\Service\SeoService;
use CMS\Modules\Seo\Service\SitemapService;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Service\SiteService;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Sitemap generation.
 *
 * The sitemap is a generated artifact: generating it scans content, but serving it
 * must never do so. Only published, indexable content belongs in it.
 */
#[CoversClass(SitemapService::class)]
final class SitemapServiceTest extends ModuleDatabaseTestCase
{
    private SitemapServiceInterface $sitemap;

    private ContentServiceInterface $contents;

    private SeoService $seo;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = $this->createSiteRow('main', 'example.com');

        $clock = new FrozenClock();
        $contentRepository = new PdoContentRepository($this->db);
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

        $this->seo = new SeoService(
            new PdoSeoRepository($this->db),
            $this->contents,
            new ContentTypeRegistry(),
            new UrlGenerator($registry),
            $siteService,
            new UserService(new PdoUserRepository($this->db), $clock),
            new MediaService(
                new PdoMediaRepository($this->db),
                new ImageProcessor(),
                new NullMediaUsageChecker(),
                new RecordingEventDispatcher(),
                $clock,
                $this->path('public/uploads'),
            ),
        );

        $query = new ContentQueryService($contentRepository, $registry);

        $this->sitemap = new SitemapService(
            $query,
            new UrlGenerator($registry),
            $siteService,
            $this->seo,
            $this->path('cache/system/sitemaps'),
        );
    }

    private function publish(string $title): int
    {
        $content = $this->contents->create($this->siteId, [
            'title' => $title,
            'author_id' => 1,
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
        ]);

        return (int) $content->id;
    }

    #[Test]
    public function publishedContentAppearsInTheSitemap(): void
    {
        $this->publish('First Post');
        $this->publish('Second Post');

        $written = $this->sitemap->generate($this->siteId);

        self::assertSame(2, $written);

        $xml = (string) $this->sitemap->read($this->siteId);

        self::assertStringContainsString('<urlset', $xml);
        self::assertStringContainsString('/blog/first-post', $xml);
        self::assertStringContainsString('/blog/second-post', $xml);
    }

    #[Test]
    public function draftsAreExcluded(): void
    {
        $this->publish('Published One');
        $this->contents->create($this->siteId, ['title' => 'Still A Draft', 'author_id' => 1]);

        self::assertSame(1, $this->sitemap->generate($this->siteId));

        $xml = (string) $this->sitemap->read($this->siteId);

        self::assertStringNotContainsString('still-a-draft', $xml);
    }

    #[Test]
    public function noindexedContentIsExcluded(): void
    {
        $contentId = $this->publish('Hidden From Search');

        $this->seo->store($contentId, $this->siteId, ['robots_index' => false]);

        self::assertSame(0, $this->sitemap->generate($this->siteId));

        $xml = (string) $this->sitemap->read($this->siteId);

        self::assertStringNotContainsString('hidden-from-search', $xml);
    }

    #[Test]
    public function theSitemapIsWellFormedXml(): void
    {
        $this->publish('Xml Check');

        $this->sitemap->generate($this->siteId);

        $xml = (string) $this->sitemap->read($this->siteId);

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        self::assertNotFalse($document);
    }

    #[Test]
    public function invalidateRemovesTheArtifact(): void
    {
        $this->publish('To Be Invalidated');
        $this->sitemap->generate($this->siteId);

        self::assertNotNull($this->sitemap->read($this->siteId));

        $this->sitemap->invalidate($this->siteId);

        self::assertNull($this->sitemap->read($this->siteId));
    }

    #[Test]
    public function readReturnsNullBeforeGeneration(): void
    {
        self::assertNull($this->sitemap->read($this->siteId));
    }

    #[Test]
    public function eachSiteGetsItsOwnSitemapFile(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        self::assertNotSame(
            $this->sitemap->pathFor($this->siteId),
            $this->sitemap->pathFor($otherSite)
        );
    }
}
