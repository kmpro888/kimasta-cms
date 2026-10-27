<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Site settings.
 *
 * Every one of these values appears on every page: the name in the header and footer, the locale in
 * `<html lang>`, the timezone in rendered dates, the theme everywhere. So a save clears the site's
 * page cache, and one test proves the change is visible to a visitor afterwards.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\SettingsController::class)]
final class AdminSettingsTest extends AdminTestCase
{
    private function site(): \CMS\Shared\Contracts\SiteContextInterface
    {
        return $this->app->container()->get(SiteServiceInterface::class)->findById($this->siteId);
    }

    private function article(string $slug): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Settings Article',
            'slug' => $slug,
            'author_id' => $this->makeUser('writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    #[Test]
    public function theScreenRequiresTheSettingsCapability(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        self::assertSame(403, $this->get('/admin/settings')->status());
        self::assertSame(403, $this->post('/admin/settings', ['name' => 'Hijacked'])->status());
    }

    #[Test]
    public function itShowsTheCurrentValues(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->get('/admin/settings');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString($this->site()->name(), $html);
        self::assertStringContainsString($this->site()->host(), $html);
        self::assertStringContainsString('readonly', $html, 'The domain is not editable here.');
    }

    #[Test]
    public function savingChangesTheSiteAndPersists(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/settings', [
            'name' => 'Renamed Site',
            'default_locale' => 'id',
            'timezone' => 'Asia/Jakarta',
            'base_url' => 'https://example.com/',
        ]);

        self::assertSame(303, $response->status());

        $site = $this->site();

        self::assertSame('Renamed Site', $site->name());
        self::assertSame('id', $site->defaultLocale());
        self::assertSame('Asia/Jakarta', $site->timezone());
        self::assertSame('https://example.com', $site->baseUrl(), 'The trailing slash is trimmed.');
    }

    #[Test]
    public function anUnknownTimezoneIsRefusedAndNothingChanges(): void
    {
        $before = $this->site()->name();
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/settings', [
            'name' => 'Should Not Apply',
            'default_locale' => 'en',
            'timezone' => 'Mars/Olympus',
        ]);

        self::assertSame(422, $response->status());
        self::assertSame($before, $this->site()->name());
    }

    #[Test]
    public function aMalformedLocaleOrBaseUrlIsRefused(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $badLocale = $this->post('/admin/settings', [
            'name' => 'Site',
            'default_locale' => 'not a locale',
            'timezone' => 'UTC',
        ]);

        self::assertSame(422, $badLocale->status());

        $badUrl = $this->post('/admin/settings', [
            'name' => 'Site',
            'default_locale' => 'en',
            'timezone' => 'UTC',
            'base_url' => 'example.com',
        ]);

        self::assertSame(422, $badUrl->status());
    }

    #[Test]
    public function anEmptyNameIsRefused(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/settings', [
            'name' => '',
            'default_locale' => 'en',
            'timezone' => 'UTC',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('A site needs a name', $this->body($response));
    }

    #[Test]
    public function savingClearsThePageCacheAndTheNewNameIsVisible(): void
    {
        $this->article('settings-visible');
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $before = $this->app->handle(Request::create('GET', '/blog/settings-visible', $this->host));
        self::assertSame('MISS', $before->header('X-Cache'));
        self::assertStringContainsString($this->site()->name(), $before->content());

        $this->post('/admin/settings', [
            'name' => 'Visible New Name',
            'default_locale' => 'en',
            'timezone' => 'UTC',
            'base_url' => '',
        ]);

        $after = $this->app->handle(Request::create('GET', '/blog/settings-visible', $this->host));

        self::assertSame('MISS', $after->header('X-Cache'), 'The cache must have been cleared.');
        self::assertStringContainsString('Visible New Name', $after->content());
    }

    #[Test]
    public function theThemeCanBeSwitchedAndAnUnknownThemeIsRefused(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $unknown = $this->post('/admin/settings/theme', ['theme' => 'not-installed']);

        self::assertSame(303, $unknown->status());
        self::assertSame(
            'default',
            $this->app->container()->get(\CMS\Modules\Theme\Contracts\ThemeRegistryInterface::class)
                ->activeFor($this->siteId)->name,
            'An unknown theme must leave the active one alone.'
        );

        $ok = $this->post('/admin/settings/theme', ['theme' => 'default']);

        self::assertSame(303, $ok->status());
    }

    #[Test]
    public function anEditorCannotSwitchTheTheme(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        // `themes.manage` belongs to the administrator role; `settings.update` is not enough.
        self::assertSame(403, $this->post('/admin/settings/theme', ['theme' => 'default'])->status());
    }

    #[Test]
    public function savingNeedsACsrfToken(): void
    {
        $before = $this->site()->name();
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/settings', ['name' => 'No Token'], false);

        self::assertSame(403, $response->status());
        self::assertSame($before, $this->site()->name());
    }

    #[Test]
    public function theSettingsEntryIsOnlyInTheMenuForSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));
        self::assertStringNotContainsString('href="/admin/settings"', $this->body($this->get('/admin')));

        $this->loginAs($this->makeUser('admin', 'administrator'));
        self::assertStringContainsString('href="/admin/settings"', $this->body($this->get('/admin')));
    }
}
