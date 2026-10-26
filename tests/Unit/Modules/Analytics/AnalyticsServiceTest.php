<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Analytics;

use CMS\Modules\Analytics\Contracts\AnalyticsServiceInterface;
use CMS\Modules\Analytics\Service\AnalyticsService;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Analytics snippet rendering.
 *
 * This is the one place where a setting becomes executable JavaScript in every page,
 * so the tests concentrate on what cannot be allowed out: quotes, tags, schemes and
 * anything that would break out of the emitted string.
 */
#[CoversClass(AnalyticsService::class)]
final class AnalyticsServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function service(array $settings): AnalyticsService
    {
        $settingsService = new class ($settings) implements SettingsServiceInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values)
            {
            }

            public function get(int $siteId, string $key, string $namespace = 'general', mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function all(int $siteId, string $namespace = 'general'): array
            {
                return $this->values;
            }

            public function autoloaded(int $siteId): array
            {
                return $this->values;
            }

            public function set(
                int $siteId,
                string $key,
                mixed $value,
                string $namespace = 'general',
                bool $autoload = false,
            ): void {
                $this->values[$key] = $value;
            }

            public function forget(int $siteId, string $key, string $namespace = 'general'): void
            {
                unset($this->values[$key]);
            }

            public function invalidate(int $siteId): void
            {
            }
        };

        return new AnalyticsService($settingsService);
    }

    #[Test]
    public function nothingIsRenderedWhenDisabled(): void
    {
        self::assertSame('', $this->service(['provider' => 'none'])->render(1));
    }

    #[Test]
    public function nothingIsRenderedForAnUnknownProvider(): void
    {
        // Failing closed matters: an unrecognised value must not fall through to some
        // default tracker.
        self::assertSame('', $this->service(['provider' => 'matomo-v2'])->render(1));
    }

    #[Test]
    public function aGoogleSnippetIsRendered(): void
    {
        $html = $this->service([
            'provider' => 'google',
            'google_id' => 'G-ABCD1234',
        ])->render(1);

        self::assertStringContainsString('googletagmanager.com/gtag/js?id=G-ABCD1234', $html);
        self::assertStringContainsString("gtag('config','G-ABCD1234')", $html);
    }

    #[Test]
    public function aLegacyGoogleAnalyticsIdIsAccepted(): void
    {
        $html = $this->service([
            'provider' => 'google',
            'google_id' => 'UA-12345-1',
        ])->render(1);

        self::assertStringContainsString('UA-12345-1', $html);
    }

    #[Test]
    public function aGoogleIdWithMarkupIsRefused(): void
    {
        // The identifier is interpolated into a JavaScript string literal, so a quote
        // would end the literal.
        $html = $this->service([
            'provider' => 'google',
            'google_id' => "G-1');alert(1);//",
        ])->render(1);

        self::assertSame('', $html);
    }

    #[Test]
    public function aGoogleIdContainingATagIsRefused(): void
    {
        $html = $this->service([
            'provider' => 'google',
            'google_id' => 'G-<script>alert(1)</script>',
        ])->render(1);

        self::assertSame('', $html);
    }

    #[Test]
    public function aMatomoSnippetIsRendered(): void
    {
        $html = $this->service([
            'provider' => 'matomo',
            'matomo_host' => 'stats.example.com',
            'matomo_site_id' => '7',
        ])->render(1);

        self::assertStringContainsString('https://stats.example.com/', $html);
        self::assertStringContainsString("setSiteId','7'", $html);
        self::assertStringContainsString('matomo.js', $html);
    }

    #[Test]
    public function aMatomoHostWithASchemeIsRefused(): void
    {
        // The tracker URL is forced to https, and a configured scheme must not be able
        // to smuggle a path or a quote into the literal.
        $html = $this->service([
            'provider' => 'matomo',
            'matomo_host' => 'https://evil.test/x?y=',
            'matomo_site_id' => '7',
        ])->render(1);

        self::assertSame('', $html);
    }

    #[Test]
    public function aMatomoHostCannotContainQuotes(): void
    {
        $html = $this->service([
            'provider' => 'matomo',
            'matomo_host' => "evil.test');alert(1);//",
            'matomo_site_id' => '7',
        ])->render(1);

        self::assertSame('', $html);
    }

    #[Test]
    public function aMatomoSnippetNeedsANumericSiteId(): void
    {
        $html = $this->service([
            'provider' => 'matomo',
            'matomo_host' => 'stats.example.com',
            'matomo_site_id' => "1');alert(1);//",
        ])->render(1);

        self::assertSame('', $html);
    }

    #[Test]
    public function aMissingGoogleIdRendersNothing(): void
    {
        self::assertSame('', $this->service(['provider' => 'google'])->render(1));
    }

    #[Test]
    public function theProviderIsReported(): void
    {
        self::assertSame('matomo', $this->service(['provider' => 'matomo'])->provider(1));
        self::assertSame('none', $this->service([])->provider(1));
    }

    #[Test]
    public function theContractIsImplemented(): void
    {
        self::assertInstanceOf(AnalyticsServiceInterface::class, $this->service([]));
    }
}
