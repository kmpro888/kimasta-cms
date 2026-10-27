<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Theme assets are served by a route, because `themes/` lives outside the document root.
 *
 * The regression this exists for: `head.php` linked `/themes/default/assets/theme.css`, nothing
 * served that path, and **the public site was unstyled for its whole life** while 1246 tests
 * stayed green. HTML tests check markup; nobody looked.
 *
 * The last test here is the one that matters most: it fetches the page, extracts the stylesheet
 * URL the page actually emits, and requests it. That is the loop that was broken.
 */
#[CoversClass(\CMS\Modules\Theme\Http\ThemeAssetController::class)]
final class ThemeAssetRouteTest extends AppTestCase
{
    private function fetch(string $path): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host));
    }

    #[Test]
    public function theStylesheetIsServedAsCss(): void
    {
        $response = $this->fetch('/themes/default/assets/theme.css');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('text/css', (string) $response->header('Content-Type'));
        self::assertStringContainsString('--accent', $response->content(), 'The file content, not a placeholder.');
    }

    #[Test]
    public function assetsAreCacheableAndNotSniffable(): void
    {
        $response = $this->fetch('/themes/default/assets/theme.css');

        self::assertStringContainsString('max-age', (string) $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertNotNull($response->header('Last-Modified'));
    }

    #[Test]
    public function thePageLinksAStylesheetThatActuallyResolves(): void
    {
        $page = $this->fetch('/blog');

        self::assertSame(200, $page->status());

        self::assertMatchesRegularExpression(
            '#<link rel="stylesheet" href="(/themes/[^"]+/theme\.css\?v=\d+)">#',
            $page->content(),
            'The page must link the versioned stylesheet URL.'
        );

        preg_match('#<link rel="stylesheet" href="([^"]+)">#', $page->content(), $matches);
        $href = $matches[1] ?? '';

        self::assertNotSame('', $href);

        // Strip the cache-busting query, then request exactly what the browser would.
        $path = explode('?', $href)[0];
        $css = $this->fetch($path);

        self::assertSame(200, $css->status(), 'The href the page emits must resolve, or the site renders unstyled.');
        self::assertStringContainsString('text/css', (string) $css->header('Content-Type'));
    }

    #[Test]
    public function theVersionQueryChangesWhenTheStylesheetDoes(): void
    {
        $page = $this->fetch('/blog');

        preg_match('#theme\.css\?v=(\d+)#', $page->content(), $matches);

        self::assertGreaterThan(1, (int) ($matches[1] ?? 0), 'A version must be present, derived from the file.');
    }

    #[Test]
    public function anUnknownThemeIsA404(): void
    {
        self::assertSame(404, $this->fetch('/themes/not-a-theme/assets/theme.css')->status());
    }

    #[Test]
    public function aPhpFileInTheAssetsDirectoryIsNotServed(): void
    {
        // The allow-list is by extension: even if a stray .php existed there, it must not be
        // handed out as a file.
        self::assertSame(404, $this->fetch('/themes/default/assets/theme.php')->status());
        self::assertSame(404, $this->fetch('/themes/default/assets/shell.js.php')->status());
    }

    #[Test]
    public function aMissingAssetIsA404(): void
    {
        self::assertSame(404, $this->fetch('/themes/default/assets/nope.css')->status());
    }

    #[Test]
    public function pathTraversalIsRefused(): void
    {
        // The route pattern forbids separators, so these are rejected before the controller.
        // Tested anyway: a future change to the pattern must not become a file-read primitive.
        foreach ([
            '/themes/default/assets/..%2f..%2f.env',
            '/themes/default/assets/..%2F..%2Fconfig%2Fapp.php',
            '/themes/default/assets/%2e%2e%2f.env',
        ] as $path) {
            self::assertContains($this->fetch($path)->status(), [403, 404], $path . ' must not be served.');
        }
    }
}
