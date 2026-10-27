<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Core\Http\Request;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The ads screen.
 *
 * The last module with a full service and no screen: `ads.manage` existed in the catalogue and the
 * service could create, edit and delete placements, while the only way to add one was code.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\AdController::class)]
final class AdminAdTest extends AdminTestCase
{
    private function ads(): AdvertisingServiceInterface
    {
        return $this->app->container()->get(AdvertisingServiceInterface::class);
    }

    private function admin(): int
    {
        // A distinct username: one test signs in as an editor called "boss" first.
        return $this->makeUser('adminboss', 'administrator');
    }

    private function article(string $slug = 'ads-article'): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Ads Article',
            'slug' => $slug,
            'author_id' => $this->makeUser('writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    #[Test]
    public function theScreenRequiresTheAdsCapability(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        self::assertSame(403, $this->get('/admin/ads')->status());
        self::assertSame(403, $this->post('/admin/ads', ['name' => 'x', 'body_html' => '<b>x</b>'])->status());
    }

    #[Test]
    public function anAdCanBeCreatedAndAppearsOnTheSite(): void
    {
        $this->article();
        $this->loginAs($this->admin());

        $response = $this->post('/admin/ads', [
            'name' => 'Sponsor banner',
            'placement' => 'before_article',
            'body_html' => '<a href="https://sponsor.example">SPONSOR</a>',
            'target_url' => 'https://sponsor.example',
            'is_active' => '1',
            'priority' => '5',
        ]);

        self::assertSame(303, $response->status());
        self::assertCount(1, $this->ads()->listForSite($this->siteId));

        // The page cache is flushed on save, so the ad is visible at once. The theme prints the
        // `before_article` slot on an article page; `header` is a valid placement with no slot in
        // this theme, which is why the assertion uses the placement the template renders.
        $html = $this->app->handle(Request::create('GET', '/blog/ads-article', $this->host))->content();

        self::assertStringContainsString('https://sponsor.example', $html);
        self::assertStringContainsString('SPONSOR', $html);
    }

    #[Test]
    public function anInvalidTargetIsRefused(): void
    {
        $this->loginAs($this->admin());

        $response = $this->post('/admin/ads', [
            'name' => 'Bad link',
            'placement' => 'before_article',
            'body_html' => '<b>Ad</b>',
            'target_url' => 'javascript:alert(1)',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame([], $this->ads()->listForSite($this->siteId));
    }

    #[Test]
    public function emptyMarkupIsRefused(): void
    {
        $this->loginAs($this->admin());

        $response = $this->post('/admin/ads', [
            'name' => 'Empty',
            'placement' => 'before_article',
            'body_html' => '',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame([], $this->ads()->listForSite($this->siteId));
    }

    #[Test]
    public function switchingAnAdOffStopsItRendering(): void
    {
        $this->article();
        $this->loginAs($this->admin());

        $this->post('/admin/ads', [
            'name' => 'Temporary',
            'placement' => 'before_article',
            'body_html' => '<span>TEMPAD</span>',
            'is_active' => '1',
        ]);

        $ad = $this->ads()->listForSite($this->siteId)[0];

        $response = $this->post('/admin/ads/toggle', ['id' => (int) $ad->id]);

        self::assertSame(303, $response->status());
        self::assertFalse($this->ads()->listForSite($this->siteId)[0]->isActive);

        $html = $this->app->handle(Request::create('GET', '/blog/ads-article', $this->host))->content();
        self::assertStringNotContainsString('TEMPAD', $html);
    }

    #[Test]
    public function anAdCanBeDeleted(): void
    {
        $this->loginAs($this->admin());

        $this->post('/admin/ads', [
            'name' => 'Gone',
            'placement' => 'sidebar',
            'body_html' => '<span>GONE</span>',
            'is_active' => '1',
        ]);

        $ad = $this->ads()->listForSite($this->siteId)[0];

        self::assertSame(303, $this->post('/admin/ads/delete', ['id' => (int) $ad->id])->status());
        self::assertSame([], $this->ads()->listForSite($this->siteId));
    }

    #[Test]
    public function anUnknownAdIsA404(): void
    {
        $this->loginAs($this->admin());

        self::assertSame(404, $this->post('/admin/ads/delete', ['id' => 999999])->status());
        self::assertSame(404, $this->post('/admin/ads/toggle', ['id' => 999999])->status());
    }

    #[Test]
    public function creatingAnAdNeedsACsrfToken(): void
    {
        $this->loginAs($this->admin());

        $response = $this->post('/admin/ads', [
            'name' => 'No token',
            'placement' => 'before_article',
            'body_html' => '<b>x</b>',
        ], false);

        self::assertSame(403, $response->status());
        self::assertSame([], $this->ads()->listForSite($this->siteId));
    }

    #[Test]
    public function adsDoNotCrossTenants(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');
        $this->ads()->create($otherSite, [
            'name' => 'Theirs',
            'placement' => 'before_article',
            'body_html' => '<b>THEIRS</b>',
        ]);

        $this->loginAs($this->admin());

        $html = $this->body($this->get('/admin/ads'));

        self::assertStringNotContainsString('THEIRS', $html);
        self::assertCount(0, $this->ads()->listForSite($this->siteId));
    }

    #[Test]
    public function theAdsEntryIsOnlyShownToSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));
        self::assertStringNotContainsString('href="/admin/ads"', $this->body($this->get('/admin')));

        $this->loginAs($this->admin());
        self::assertStringContainsString('href="/admin/ads"', $this->body($this->get('/admin')));
    }
}
