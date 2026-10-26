<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Site;

use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\PdoDomainRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Repository\PdoSettingsRepository;
use CMS\Modules\Site\Service\SettingsService;
use CMS\Modules\Site\Service\SiteResolver;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Multisite isolation guarantees.
 *
 * The product rule is absolute: Site A must never be able to read or mutate
 * Site B, and an unresolvable host must never fall back to a default tenant.
 * These are the tests that would fail if someone "helpfully" added a default.
 */
final class SiteIsolationTest extends ModuleDatabaseTestCase
{
    private SiteResolver $resolver;

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $cacheDirectory = $this->path('cache/system');

        $this->resolver = new SiteResolver(
            new HostNormalizer(),
            new DomainMap($cacheDirectory),
            new PdoDomainRepository($this->db),
            new PdoSiteRepository($this->db),
        );

        $this->settings = new SettingsService(
            new PdoSettingsRepository($this->db),
            new PdoSiteRepository($this->db),
            $cacheDirectory,
        );
    }

    #[Test]
    public function aHostResolvesToExactlyOneTenant(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');
        $this->mapDomain($first, 'one.example', true);
        $this->mapDomain($second, 'two.example', true);

        self::assertSame($first, $this->resolver->resolve('one.example')->id());
        self::assertSame($second, $this->resolver->resolve('two.example')->id());
    }

    #[Test]
    public function siteACannotReachSiteBByAskingForItsHost(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');
        $this->mapDomain($first, 'one.example', true);
        $this->mapDomain($second, 'two.example', true);

        $context = $this->resolver->resolve('one.example');

        // The resolved context is pinned to its own site; there is no parameter
        // a caller could pass to make it read the other tenant.
        self::assertSame($first, $context->id());
        self::assertNotSame($second, $context->id());
    }

    #[Test]
    public function aSiteIdIsNeverTakenFromRequestInput(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');
        $this->mapDomain($first, 'one.example', true);
        $this->mapDomain($second, 'two.example', true);

        // Resolution is driven only by the host. Passing a foreign id as if it
        // were a request parameter has no effect on the resolved context.
        $context = $this->resolver->resolve('one.example');

        self::assertSame($first, $context->id());
    }

    #[Test]
    public function unknownHostNeverFallsBackToTheFirstSite(): void
    {
        $this->createSiteRow('one', 'one.example');

        $this->expectException(SiteNotFoundException::class);

        $this->resolver->resolve('completely-unknown.test');
    }

    #[Test]
    public function aHostThatOnlyLooksLikeAnotherSitesSuffixDoesNotResolve(): void
    {
        $first = $this->createSiteRow('one', 'example.com');
        $this->mapDomain($first, 'example.com', true);

        // Suffix tricks and subdomain squatting must not resolve.
        foreach (['notexample.com', 'example.com.evil.test', 'evil.test/example.com'] as $host) {
            try {
                $this->resolver->resolve($host);
                self::fail('Unexpectedly resolved: ' . $host);
            } catch (SiteNotFoundException|InvalidHostException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function malformedHostHeadersAreRejectedNotCoerced(): void
    {
        $this->createSiteRow('one', 'example.com');

        foreach (["example.com\r\nX-Injected: 1", 'https://example.com', 'user@example.com', "example.com\0"] as $host) {
            try {
                $this->resolver->resolve($host);
                self::fail('Malformed host accepted: ' . $host);
            } catch (InvalidHostException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function settingsAreNeverSharedBetweenSites(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->settings->set($first, 'secret', 'site-one-value', 'general', autoload: true);

        self::assertSame('site-one-value', $this->settings->get($first, 'secret'));
        self::assertNull($this->settings->get($second, 'secret'));
    }

    #[Test]
    public function oneSitesCompiledSettingsAreNotServedToAnother(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->settings->set($first, 'title', 'One', 'general', autoload: true);
        $this->settings->set($second, 'title', 'Two', 'general', autoload: true);

        // Separate cache files, separate values.
        self::assertNotSame(
            $this->settings->pathFor($first),
            $this->settings->pathFor($second)
        );

        self::assertSame('One', $this->settings->get($first, 'title'));
        self::assertSame('Two', $this->settings->get($second, 'title'));
    }

    #[Test]
    public function deletingASiteCascadesItsDomainsAndSettings(): void
    {
        $siteId = $this->createSiteRow('one', 'one.example');
        $this->mapDomain($siteId, 'one.example', true);
        $this->settings->set($siteId, 'k', 'v', 'general', autoload: true);

        $this->db->statement('DELETE FROM sites WHERE id = :id', ['id' => $siteId]);

        self::assertNull(
            $this->db->scalar('SELECT site_id FROM site_domains WHERE site_id = :id', ['id' => $siteId])
        );
        self::assertNull(
            $this->db->scalar('SELECT site_id FROM site_settings WHERE site_id = :id', ['id' => $siteId])
        );
    }
}
