<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Site;

use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Service\SiteResolver;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Tenant resolution against a real database.
 *
 * The most important assertions here are the negative ones: an unknown host
 * must fail as SITE_NOT_FOUND, and there must be no path that quietly assumes
 * site 1.
 */
#[CoversClass(SiteResolver::class)]
final class SiteResolverTest extends ModuleDatabaseTestCase
{
    private SiteResolver $resolver;

    private DomainMap $domainMap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainMap = new DomainMap($this->path('cache/system'));

        $this->resolver = new SiteResolver(
            new HostNormalizer(),
            $this->domainMap,
            new PdoDomainRepository($this->db),
            new PdoSiteRepository($this->db),
        );
    }

    #[Test]
    public function resolvesACustomDomain(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->mapDomain($siteId, 'example.com', true);

        $context = $this->resolver->resolve('example.com');

        self::assertSame($siteId, $context->id());
        self::assertSame('main', $context->slug());
        self::assertSame('example.com', $context->host());
        self::assertTrue($context->isActive());
    }

    #[Test]
    public function resolvesASubdomain(): void
    {
        $siteId = $this->createSiteRow('news', 'news.example.com');
        $this->mapDomain($siteId, 'news.example.com', true);

        self::assertSame($siteId, $this->resolver->resolve('NEWS.Example.COM')->id());
    }

    #[Test]
    public function resolvesAnAdditionalMappedDomain(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->mapDomain($siteId, 'example.com', true);
        $this->mapDomain($siteId, 'customdomain.com');

        self::assertSame($siteId, $this->resolver->resolve('customdomain.com')->id());
    }

    #[Test]
    public function contextHostIsTheRequestedHostNotThePrimaryOne(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->mapDomain($siteId, 'example.com', true);
        $this->mapDomain($siteId, 'alias.example.org');

        $context = $this->resolver->resolve('alias.example.org');

        self::assertSame($siteId, $context->id());
        self::assertSame('alias.example.org', $context->host());
    }

    #[Test]
    public function unknownDomainRaisesSiteNotFound(): void
    {
        $this->createSiteRow('main', 'example.com');

        try {
            $this->resolver->resolve('nope.test');
            self::fail('Expected SiteNotFoundException.');
        } catch (SiteNotFoundException $exception) {
            self::assertSame('SITE_NOT_FOUND', $exception->errorCode());
            self::assertSame(404, $exception->status());
        }
    }

    #[Test]
    public function malformedHostRaisesInvalidHost(): void
    {
        $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidHostException::class);

        $this->resolver->resolve('https://example.com/admin');
    }

    #[Test]
    public function emptyDatabaseHasNoImplicitDefaultSite(): void
    {
        // No sites at all: every host, including one named like a default,
        // must fail rather than resolve to an assumed site 1.
        $this->expectException(SiteNotFoundException::class);

        $this->resolver->resolve('localhost');
    }

    #[Test]
    public function siteIdOneIsNotASpecialCase(): void
    {
        // Two sites exist; the second must resolve to itself even though the
        // first one occupies id 1.
        $this->createSiteRow('one', 'one.example');
        $twoId = $this->createSiteRow('two', 'two.example');
        $this->mapDomain($twoId, 'two.example', true);

        self::assertSame($twoId, $this->resolver->resolve('two.example')->id());
    }

    #[Test]
    public function compiledMapAnswersWithoutAnyDomainRows(): void
    {
        // Compile the map, then delete the mapping rows. Resolution must still
        // succeed, which proves the map is consulted before the database.
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->mapDomain($siteId, 'example.com', true);

        $this->domainMap->compile(['example.com' => $siteId]);

        $this->db->statement('DELETE FROM site_domains');

        self::assertSame($siteId, $this->resolver->resolve('example.com')->id());
    }

    #[Test]
    public function staleMapEntryFallsThroughToTheDatabase(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->mapDomain($siteId, 'example.com', true);

        // Map points at a site that no longer exists.
        $this->domainMap->compile(['example.com' => 999]);

        // The database still knows the truth, so resolution succeeds.
        self::assertSame($siteId, $this->resolver->resolve('example.com')->id());
    }

    #[Test]
    public function resolveByIdRejectsAnUnknownId(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->resolver->resolveById(12345);
    }

    #[Test]
    public function resolveByIdRejectsNonPositiveIds(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->resolver->resolveById(0);
    }

    #[Test]
    public function inactiveSiteStillResolvesButReportsItselfInactive(): void
    {
        $siteId = $this->createSiteRow('off', 'off.example', 'inactive');
        $this->mapDomain($siteId, 'off.example', true);

        $context = $this->resolver->resolve('off.example');

        self::assertFalse($context->isActive());
    }

    #[Test]
    public function tryResolveReturnsNullForUnknownHosts(): void
    {
        self::assertNull($this->resolver->tryResolve('nope.test'));
    }
}
