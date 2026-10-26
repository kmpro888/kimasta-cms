<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Site;

use CMS\Modules\Site\Exceptions\DuplicateDomainException;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Service\SiteService;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Domain mapping administration.
 *
 * Every mutating method must leave the compiled domain map in sync, so each
 * test asserts the map file as well as the rows.
 */
#[CoversClass(SiteService::class)]
final class SiteServiceTest extends ModuleDatabaseTestCase
{
    private SiteService $service;

    private DomainMap $domainMap;

    private PdoDomainRepository $domains;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainMap = new DomainMap($this->path('cache/system'));
        $this->domains = new PdoDomainRepository($this->db);

        $this->service = new SiteService(
            new PdoSiteRepository($this->db),
            $this->domains,
            $this->domainMap,
            new HostNormalizer(),
        );
    }

    #[Test]
    public function addDomainPersistsAndRegeneratesTheMap(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->addDomain($siteId, 'extra.example');

        self::assertContains('extra.example', $this->domains->domainsForSite($siteId));

        $fresh = new DomainMap($this->path('cache/system'));

        self::assertSame($siteId, $fresh->siteIdFor('extra.example'));
    }

    #[Test]
    public function addDomainNormalizesTheInput(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->addDomain($siteId, '  MiXeD.Example.COM.:8080 ');

        self::assertContains('mixed.example.com', $this->domains->domainsForSite($siteId));
    }

    #[Test]
    public function addDomainRejectsAnInvalidHost(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidHostException::class);

        $this->service->addDomain($siteId, 'https://example.com/x');
    }

    #[Test]
    public function addDomainRejectsAnUnknownSite(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->service->addDomain(4242, 'example.com');
    }

    #[Test]
    public function duplicateDomainIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->service->addDomain($siteId, 'extra.example');

        $this->expectException(DuplicateDomainException::class);

        $this->service->addDomain($siteId, 'extra.example');
    }

    #[Test]
    public function aDomainCannotBeMappedToTwoSites(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->service->addDomain($first, 'shared.example');

        try {
            $this->service->addDomain($second, 'shared.example');
            self::fail('Expected DuplicateDomainException.');
        } catch (DuplicateDomainException $exception) {
            self::assertSame(409, $exception->status());
            self::assertSame('CONFLICT', $exception->errorCode());
        }

        // The original mapping is untouched.
        self::assertSame($first, $this->domains->findSiteIdByDomain('shared.example'));
    }

    #[Test]
    public function removeDomainDeletesAndRegenerates(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->service->addDomain($siteId, 'extra.example');

        $this->service->removeDomain($siteId, 'extra.example');

        self::assertNotContains('extra.example', $this->domains->domainsForSite($siteId));

        $fresh = new DomainMap($this->path('cache/system'));

        self::assertNull($fresh->siteIdFor('extra.example'));
    }

    #[Test]
    public function regenerateDomainMapReturnsTheMappingCount(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->mapDomain($siteId, 'example.com', true);
        $this->mapDomain($siteId, 'second.example');
        $this->mapDomain($siteId, 'third.example');

        self::assertSame(3, $this->service->regenerateDomainMap());
    }

    #[Test]
    public function regenerateIncludesPrimaryDomainFromTheSitesTable(): void
    {
        $siteId = $this->createSiteRow('main', 'primary.example');

        // No site_domains rows at all; the sites.primary_domain column is the
        // only source.
        $count = $this->service->regenerateDomainMap();

        self::assertSame(1, $count);
        self::assertSame($siteId, (new DomainMap($this->path('cache/system')))->siteIdFor('primary.example'));
    }

    #[Test]
    public function findByIdRejectsUnknownAndInvalidIds(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->service->findById(0);
    }

    #[Test]
    public function findBySlugReturnsTheSite(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        self::assertSame($siteId, $this->service->findBySlug('main')->id());
    }

    #[Test]
    public function findBySlugRejectsAnUnknownSlug(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->service->findBySlug('missing');
    }

    #[Test]
    public function createRegistersTheSiteAndItsPrimaryDomain(): void
    {
        $site = $this->service->create(
            name: 'Brand New',
            slug: 'brand-new',
            primaryDomain: 'BrandNew.Example.COM',
            baseUrl: 'https://brandnew.example.com',
        );

        self::assertSame('brand-new', $site->slug());
        self::assertSame('brandnew.example.com', $site->host());

        self::assertSame($site->id(), $this->domains->findSiteIdByDomain('brandnew.example.com'));
        self::assertSame($site->id(), (new DomainMap($this->path('cache/system')))->siteIdFor('brandnew.example.com'));
    }

    #[Test]
    public function allReturnsEverySite(): void
    {
        $this->createSiteRow('one', 'one.example');
        $this->createSiteRow('two', 'two.example');

        self::assertCount(2, $this->service->all());
    }
}
