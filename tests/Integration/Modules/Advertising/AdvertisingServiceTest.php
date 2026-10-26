<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Advertising;

use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Advertising\Entity\Ad;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Ad selection and rendering.
 *
 * The rules that matter are the ones that keep an ad out of the wrong place: the date
 * window, targeting, and an unknown rule type making the ad ineligible rather than being
 * ignored.
 */
#[CoversClass(\CMS\Modules\Advertising\Service\AdvertisingService::class)]
#[CoversClass(Ad::class)]
final class AdvertisingServiceTest extends AppTestCase
{
    private AdvertisingServiceInterface $ads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ads = $this->app->container()->get(AdvertisingServiceInterface::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function ad(array $overrides = []): Ad
    {
        return $this->ads->create($this->siteId, array_merge([
            'name' => 'Test banner',
            'placement' => 'sidebar',
            'body_html' => '<img src="/banner.png" alt="Ad">',
        ], $overrides));
    }

    #[Test]
    public function anAdIsReturnedForItsPlacement(): void
    {
        $this->ad(['placement' => 'sidebar']);

        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar'));
        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'footer'));
    }

    #[Test]
    public function anUnknownPlacementIsRejectedRatherThanReturningNothing(): void
    {
        // A typo in a template must fail visibly, not render an empty slot forever.
        $this->expectException(\InvalidArgumentException::class);

        $this->ads->forPlacement($this->siteId, 'sidebaar');
    }

    #[Test]
    public function anInactiveAdIsNotReturned(): void
    {
        $this->ad(['is_active' => false]);

        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'sidebar'));
    }

    #[Test]
    public function anAdOutsideItsDateWindowIsNotReturned(): void
    {
        // Windows are computed relative to the real clock the container uses, so this
        // test does not depend on today's date.
        $this->ad([
            'name' => 'Expired',
            'starts_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'ends_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
        ]);

        $this->ad([
            'name' => 'Future',
            'starts_at' => date('Y-m-d H:i:s', strtotime('+5 days')),
        ]);

        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'sidebar'));
    }

    #[Test]
    public function anAdInsideItsDateWindowIsReturned(): void
    {
        $this->ad([
            'starts_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'ends_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar'));
    }

    #[Test]
    public function aDeviceRuleLimitsTheAd(): void
    {
        $this->ad(['rules' => ['device' => ['mobile']]]);

        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar', ['device' => 'mobile']));
        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'sidebar', ['device' => 'desktop']));
    }

    #[Test]
    public function aContentTypeRuleLimitsTheAd(): void
    {
        $this->ad(['rules' => ['content_type' => ['post']]]);

        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar', ['content_type' => 'post']));
        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'sidebar', ['content_type' => 'page']));
    }

    #[Test]
    public function aTermRuleMatchesOnAnyOverlap(): void
    {
        $this->ad(['rules' => ['term' => ['5', '9']]]);

        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar', ['term_ids' => [9, 12]]));
        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'sidebar', ['term_ids' => [1, 2]]));
    }

    #[Test]
    public function rulesAreAndedTogether(): void
    {
        $this->ad(['rules' => ['device' => ['mobile'], 'content_type' => ['post']]]);

        // Both must hold.
        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar', [
            'device' => 'mobile',
            'content_type' => 'post',
        ]));

        self::assertCount(0, $this->ads->forPlacement($this->siteId, 'sidebar', [
            'device' => 'mobile',
            'content_type' => 'page',
        ]));
    }

    #[Test]
    public function anUntargetedAdMatchesEverything(): void
    {
        $this->ad();

        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar', [
            'device' => 'tablet',
            'content_type' => 'page',
            'term_ids' => [1],
        ]));
    }

    #[Test]
    public function anUnsupportedRuleTypeIsRefusedAtWriteTime(): void
    {
        // A stored rule that can never match would be a silent outage for that ad.
        $this->expectException(\InvalidArgumentException::class);

        $this->ad(['rules' => ['browser' => ['firefox']]]);
    }

    #[Test]
    public function anUnsupportedStoredRuleMakesTheAdIneligible(): void
    {
        $ad = $this->ad();

        // Simulate a rule written by a future version: the ad must not run anywhere
        // rather than running everywhere.
        $stored = new Ad(
            id: $ad->id,
            siteId: $ad->siteId,
            name: $ad->name,
            placement: $ad->placement,
            bodyHtml: $ad->bodyHtml,
            targetUrl: null,
            isActive: true,
            priority: 0,
            startsAt: null,
            endsAt: null,
            rules: ['browser' => ['firefox']],
        );

        self::assertFalse($stored->matchesRules(['device' => 'firefox']));
    }

    #[Test]
    public function higherPriorityComesFirst(): void
    {
        $this->ad(['name' => 'Low', 'priority' => 1]);
        $this->ad(['name' => 'High', 'priority' => 10]);

        $names = array_map(
            static fn (Ad $ad): string => $ad->name,
            $this->ads->forPlacement($this->siteId, 'sidebar')
        );

        self::assertSame(['High', 'Low'], $names);
    }

    #[Test]
    public function renderingWrapsTheCreativeAndMarksItAsAnAd(): void
    {
        $this->ad(['body_html' => '<img src="/banner.png">']);

        $html = $this->ads->render($this->siteId, 'sidebar');

        self::assertStringContainsString('class="ad"', $html);
        self::assertStringContainsString('data-placement="sidebar"', $html);
        self::assertStringContainsString('<img src="/banner.png">', $html);
    }

    #[Test]
    public function aTargetUrlIsEscapedAndMarkedSponsored(): void
    {
        $this->ad([
            'body_html' => 'Buy now',
            'target_url' => 'https://ads.example.com/click?a=1&b=2',
        ]);

        $html = $this->ads->render($this->siteId, 'sidebar');

        self::assertStringContainsString('rel="nofollow sponsored noopener"', $html);
        // Ampersands in the URL are escaped so the attribute cannot be broken out of.
        self::assertStringContainsString('a=1&amp;b=2', $html);
    }

    #[Test]
    public function anEmptyPlacementRendersAnEmptyString(): void
    {
        self::assertSame('', $this->ads->render($this->siteId, 'footer'));
    }

    #[Test]
    public function severalPlacementsAreRenderedInOneCall(): void
    {
        $this->ad(['placement' => 'header', 'name' => 'Head']);
        $this->ad(['placement' => 'footer', 'name' => 'Foot']);

        $slots = $this->ads->renderPlacements($this->siteId, ['header', 'sidebar', 'footer']);

        self::assertNotSame('', $slots['header']);
        self::assertSame('', $slots['sidebar']);
        self::assertNotSame('', $slots['footer']);
    }

    #[Test]
    public function adsAreIsolatedPerSite(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->ad(['name' => 'Mine']);

        self::assertCount(0, $this->ads->forPlacement($otherSite, 'sidebar'));
        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar'));
    }

    #[Test]
    public function updatingReplacesTheRules(): void
    {
        $ad = $this->ad(['rules' => ['device' => ['mobile']]]);

        $updated = $this->ads->update((int) $ad->id, $this->siteId, ['rules' => ['device' => ['desktop']]]);

        self::assertSame(['device' => ['desktop']], $updated->rules);
    }

    #[Test]
    public function deletingRemovesTheAdAndItsRules(): void
    {
        $ad = $this->ad(['rules' => ['device' => ['mobile']]]);

        self::assertTrue($this->ads->delete((int) $ad->id, $this->siteId));

        $rules = $this->db->selectOne('SELECT COUNT(*) AS total FROM ad_rules');
        self::assertSame(0, (int) $rules['total']);
    }

    #[Test]
    public function aCrossSiteAdCannotBeDeleted(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');
        $ad = $this->ad();

        self::assertFalse($this->ads->delete((int) $ad->id, $otherSite));
        self::assertCount(1, $this->ads->forPlacement($this->siteId, 'sidebar'));
    }
}
