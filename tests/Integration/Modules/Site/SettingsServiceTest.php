<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Site;

use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\PdoSettingsRepository;
use CMS\Modules\Site\Repository\PdoSiteRepository;
use CMS\Modules\Site\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Per-site settings and the compiled autoload cache.
 */
#[CoversClass(SettingsService::class)]
final class SettingsServiceTest extends ModuleDatabaseTestCase
{
    private SettingsService $settings;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDirectory = $this->path('cache/system');

        $this->settings = new SettingsService(
            new PdoSettingsRepository($this->db),
            new PdoSiteRepository($this->db),
            $this->cacheDirectory,
        );
    }

    #[Test]
    public function setThenGetRoundTripsAScalar(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'site_title', 'My Site');

        self::assertSame('My Site', $this->settings->get($siteId, 'site_title'));
    }

    #[Test]
    public function getReturnsTheDefaultForAMissingKey(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        self::assertSame('fallback', $this->settings->get($siteId, 'nope', 'general', 'fallback'));
    }

    #[Test]
    public function structuredValuesRoundTrip(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $value = ['per_page' => 20, 'flags' => ['a', 'b'], 'enabled' => true];

        $this->settings->set($siteId, 'pagination', $value);

        self::assertSame($value, $this->settings->get($siteId, 'pagination'));
    }

    #[Test]
    public function falseIsPreservedRatherThanTreatedAsMissing(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'off', false);

        // A stored false must not be replaced by the caller's default.
        self::assertFalse($this->settings->get($siteId, 'off', 'general', true));
    }

    #[Test]
    public function namespacesAreIsolated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'title', 'General title');
        $this->settings->set($siteId, 'title', 'SEO title', 'seo');

        self::assertSame('General title', $this->settings->get($siteId, 'title'));
        self::assertSame('SEO title', $this->settings->get($siteId, 'title', 'seo'));
    }

    #[Test]
    public function sitesDoNotShareSettings(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->settings->set($first, 'title', 'Site one');
        $this->settings->set($second, 'title', 'Site two');

        self::assertSame('Site one', $this->settings->get($first, 'title'));
        self::assertSame('Site two', $this->settings->get($second, 'title'));
    }

    #[Test]
    public function allReturnsEveryKeyInANamespace(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'a', 1, 'seo');
        $this->settings->set($siteId, 'b', 2, 'seo');
        $this->settings->set($siteId, 'c', 3);

        self::assertSame(['a' => 1, 'b' => 2], $this->settings->all($siteId, 'seo'));
    }

    #[Test]
    public function autoloadedSettingsAreCompiledToAFile(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'site_title', 'Compiled', 'general', autoload: true);

        $path = $this->settings->pathFor($siteId);

        self::assertFileExists($path);

        /** @var array<string, array<string, mixed>> $compiled */
        $compiled = require $path;

        self::assertSame('Compiled', $compiled['general']['site_title']);
    }

    #[Test]
    public function autoloadReadServedFromCompiledFileWithoutTouchingTheTable(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'site_title', 'FromCache', 'general', autoload: true);

        // Delete the row; a fresh service instance must still answer from the
        // compiled file, proving the cache-first read path.
        $this->db->statement('DELETE FROM site_settings');

        $fresh = new SettingsService(
            new PdoSettingsRepository($this->db),
            new PdoSiteRepository($this->db),
            $this->cacheDirectory,
        );

        self::assertSame('FromCache', $fresh->get($siteId, 'site_title'));
    }

    #[Test]
    public function forgetRemovesTheKeyFromBothStoreAndCompiledCache(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'site_title', 'Temp', 'general', autoload: true);
        $this->settings->set($siteId, 'keep_me', 'Stays', 'general', autoload: true);
        $this->settings->forget($siteId, 'site_title');

        self::assertNull($this->settings->get($siteId, 'site_title'));

        // The compiled file is rebuilt after a write, so it must exist but no
        // longer carry the forgotten key.
        /** @var array<string, array<string, mixed>> $compiled */
        $compiled = require $this->settings->pathFor($siteId);

        self::assertArrayNotHasKey('site_title', $compiled['general']);
        self::assertSame('Stays', $compiled['general']['keep_me']);
    }

    #[Test]
    public function invalidateDropsTheCompiledFile(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'k', 'v', 'general', autoload: true);
        $this->settings->invalidate($siteId);

        self::assertFileDoesNotExist($this->settings->pathFor($siteId));
    }

    #[Test]
    public function unknownSiteIsRejected(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->settings->get(4242, 'anything');
    }

    #[Test]
    public function nonPositiveSiteIdIsRejected(): void
    {
        $this->expectException(SiteNotFoundException::class);

        $this->settings->get(0, 'anything');
    }

    #[Test]
    public function repeatedReadsAreMemoizedWithinTheRequest(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->settings->set($siteId, 'k', 'v', 'general', autoload: true);

        // Prime the memo, then delete the backing file: subsequent reads in
        // this instance must still work.
        self::assertSame('v', $this->settings->get($siteId, 'k'));
        unlink($this->settings->pathFor($siteId));

        self::assertSame('v', $this->settings->get($siteId, 'k'));
    }
}
