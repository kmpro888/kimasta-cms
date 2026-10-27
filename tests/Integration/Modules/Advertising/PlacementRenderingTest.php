<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Advertising;

use CMS\Core\Http\Request;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Advertising\Support\PlacementRegistry;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Every placement is either printed by the theme or named as unsupported.
 *
 * The advertising model has had seven placements since the module existed, and the theme printed
 * none of them: `renderPlacements()` filled a view-model key no template read. An operator could
 * configure an ad in the admin and see nothing on the site, forever, with no way to tell why.
 *
 * These tests close that in both directions — a supported placement must appear in the rendered
 * page, and an unsupported one must be absent *and* explained.
 */
#[CoversClass(PlacementRegistry::class)]
final class PlacementRenderingTest extends AdminTestCase
{
    private function article(string $slug): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Ad Article',
            'slug' => $slug,
            'excerpt' => 'A summary.',
            'author_id' => $this->makeUser('ad-writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    private function ad(string $placement): void
    {
        $this->app->container()->get(AdvertisingServiceInterface::class)->create($this->siteId, [
            'name' => 'Ad ' . $placement,
            'placement' => $placement,
            'body_html' => '<div data-ad-marker="' . $placement . '">sponsor</div>',
            'target_url' => '',
            'is_active' => true,
            'priority' => 0,
        ]);
    }

    private function page(string $path): string
    {
        return $this->app->handle(Request::create('GET', $path, $this->host))->content();
    }

    #[Test]
    public function theRegistryAccountsForAllSevenPlacements(): void
    {
        self::assertCount(7, PlacementRegistry::all());

        $supported = PlacementRegistry::supported();
        $unsupported = array_keys(PlacementRegistry::unsupported());

        self::assertCount(4, $supported);
        self::assertCount(3, $unsupported);
        self::assertSame([], array_intersect($supported, $unsupported), 'A placement cannot be both.');
        self::assertSame(
            [],
            array_values(array_diff(PlacementRegistry::all(), array_merge($supported, $unsupported))),
            'Every placement must be decided: supported or explained.'
        );

        foreach (PlacementRegistry::unsupported() as $placement => $reason) {
            self::assertNotSame('', trim($reason), 'A placement refused without a reason: ' . $placement);
        }
    }

    #[Test]
    public function everySupportedPlacementIsPrintedOnTheArticle(): void
    {
        // Public pages are cached, and the cache is keyed by path; each test uses its own path.
        $this->article('ad-article');

        foreach (PlacementRegistry::supported() as $placement) {
            $this->ad($placement);
        }

        $html = $this->page('/blog/ad-article');

        foreach (PlacementRegistry::supported() as $placement) {
            self::assertStringContainsString(
                'data-ad-marker="' . $placement . '"',
                $html,
                'The theme claims to print the ' . $placement . ' placement but did not.'
            );

            self::assertSame(
                1,
                substr_count($html, 'data-ad-placement="' . $placement . '"'),
                'A placement slot must appear once per page: ' . $placement
            );
        }
    }

    #[Test]
    public function thePageLevelSlotsAlsoAppearOnAListing(): void
    {
        $this->article('ad-listing-article');
        $this->ad('header');
        $this->ad('footer');

        $html = $this->page('/blog');

        self::assertStringContainsString('data-ad-marker="header"', $html);
        self::assertStringContainsString('data-ad-marker="footer"', $html);
    }

    #[Test]
    public function anUnsupportedPlacementIsNeverPrinted(): void
    {
        $this->article('ad-unsupported');
        $this->ad('sticky');
        $this->ad('sidebar');
        $this->ad('middle_article');

        $html = $this->page('/blog/ad-unsupported');

        self::assertStringNotContainsString('data-ad-marker="sticky"', $html);
        self::assertStringNotContainsString('data-ad-marker="sidebar"', $html);
        self::assertStringNotContainsString('data-ad-marker="middle_article"', $html);
    }

    #[Test]
    public function theAdminScreenOffersOnlyWhatTheThemePrintsAndExplainsTheRest(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $html = $this->body($this->get('/admin/ads'));

        foreach (PlacementRegistry::supported() as $placement) {
            self::assertStringContainsString('value="' . $placement . '"', $html);
        }

        foreach (array_keys(PlacementRegistry::unsupported()) as $placement) {
            self::assertStringNotContainsString(
                'value="' . $placement . '"',
                $html,
                'An unsupported placement must not be offered as a choice.'
            );

            self::assertStringContainsString($placement, $html, 'It must still be named.');
        }

        self::assertStringContainsString('Placements this theme does not print', $html);
    }

    #[Test]
    public function anAdPostedAgainstAnUnsupportedPlacementIsNotStoredThere(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/ads', [
            'name' => 'Sneaky sticky',
            'placement' => 'sticky',
            'body_html' => '<div data-ad-marker="sneaky">x</div>',
            'is_active' => '1',
            'priority' => 0,
        ]);

        self::assertSame(303, $response->status());

        $ads = $this->app->container()->get(AdvertisingServiceInterface::class)->listForSite($this->siteId);

        self::assertCount(1, $ads);
        self::assertTrue(PlacementRegistry::isSupported($ads[0]->placement), 'Stored in a supported slot.');
        self::assertNotSame('sticky', $ads[0]->placement);
    }
}
