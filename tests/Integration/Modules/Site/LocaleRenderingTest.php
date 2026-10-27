<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Site;

use CMS\Core\Http\Request;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The locale setting changes what a person sees.
 *
 * Before this, `default_locale` was written to `<html lang>` and used for date formatting and
 * nothing else: every label stayed English. A setting that appears to work and does not is worse
 * than a setting that is absent, which is why this is proven by rendering pages rather than by
 * asserting that a value round-trips through the database.
 */
final class LocaleRenderingTest extends AdminTestCase
{
    private function useLocale(string $locale): void
    {
        $this->app->container()->get(SiteServiceInterface::class)
            ->update($this->siteId, ['default_locale' => $locale]);

        // Public pages are cached; a locale change is a presentation change, so the entry must go.
        $this->app->container()->get(PageCacheInterface::class)->flushSite($this->siteId);
    }

    private function article(string $slug): void
    {
        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Locale Article',
            'slug' => $slug,
            'excerpt' => 'A summary.',
            'author_id' => $this->makeUser('locale-writer', 'contributor'),
            'status' => 'published',
            'comment_status' => 'open',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    private function fetch(string $path): string
    {
        return $this->app->handle(Request::create('GET', $path, $this->host))->content();
    }

    #[Test]
    public function englishIsTheDefaultAndStaysEnglish(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $html = $this->fetch('/admin');

        self::assertStringContainsString('>Dashboard<', $html);
        self::assertStringContainsString('>Settings<', $html);
    }

    #[Test]
    public function switchingTheLocaleChangesTheAdminNavigation(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));
        $this->useLocale('id');

        $html = $this->fetch('/admin');

        // The nav is a list of links; the page heading stays English for now, which is the
        // limitation this report names. So the assertion targets the links.
        self::assertStringContainsString('Beranda</a>', $html, 'The navigation is translated.');
        self::assertStringContainsString('Pengaturan</a>', $html);
        self::assertStringNotContainsString('Dashboard</a>', $html, 'The English nav label must be gone.');
        self::assertStringNotContainsString('nav.dashboard', $html, 'No raw keys may leak into the page.');
    }

    #[Test]
    public function switchingTheLocaleChangesThePublicChrome(): void
    {
        $this->article('locale-article');
        $this->useLocale('id');

        $html = $this->fetch('/blog/locale-article');

        self::assertStringContainsString('Tinggalkan komentar', $html);
        self::assertStringContainsString('Nama Anda', $html);
        self::assertStringContainsString('Kirim komentar', $html);
        self::assertStringContainsString('Belum ada komentar.', $html);
        self::assertStringContainsString('lang="id"', $html);
    }

    #[Test]
    public function goingBackToEnglishRestoresTheEnglishChrome(): void
    {
        $this->article('locale-article-back');
        $this->useLocale('id');
        $this->useLocale('en');

        $html = $this->fetch('/blog/locale-article-back');

        self::assertStringContainsString('Leave a comment', $html);
        self::assertStringNotContainsString('Tinggalkan komentar', $html);
    }

    #[Test]
    public function anUnsupportedLocaleFallsBackToEnglishRatherThanBreaking(): void
    {
        $this->article('locale-article-unknown');
        $this->useLocale('zz');

        $html = $this->fetch('/blog/locale-article-unknown');

        self::assertStringContainsString('Leave a comment', $html, 'A missing catalogue must not blank the page.');
        self::assertStringNotContainsString('nav.', $html);
    }
}
