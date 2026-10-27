<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * End-to-end in a real browser.
 *
 * The other suites assert on strings the application returned. This one starts the application
 * behind a real HTTP server, loads the page in headless Chrome, and asks the resulting **DOM**
 * questions — so it fails when the CSS is unreachable, when a form control has no label, when an
 * image has no `alt`, when the page has no `<main>`, or when a fatal error leaks into the markup.
 *
 * Those are exactly the defects that survived a green test suite in this project: a public site
 * with no stylesheet because the theme asset URL 404'd, and a comment form whose inputs were
 * invisible because the CSS used variables the theme does not define. Both were found by looking.
 * This makes looking automatic.
 *
 * Chrome is not required for the suite to pass: the tests skip when no browser is installed, and
 * CI has one.
 */
#[CoversClass(\CMS\Modules\Theme\Http\PublicPageController::class)]
final class BrowserE2ETest extends AdminTestCase
{
    private static ?int $serverPort = null;
    private static $serverProcess = null;
    private static string $browser = '';
    private static string $serverError = '';
    private static string $serverLog = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$browser = self::findBrowser();

        if (self::$browser === '') {
            return;
        }

        // A free port, chosen by the OS rather than guessed.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$serverPort = (int) substr($name, strrpos($name, ':') + 1);

        $root = dirname(__DIR__, 2);
        $command = [
            PHP_BINARY,
            '-S', '127.0.0.1:' . self::$serverPort,
            '-t', $root . '/public',
            $root . '/tests/E2E/router.php',
        ];

        // Inherit, then pass on only the values the parent actually has. Defaults here would be
        // wrong in one environment or the other: locally the test database has no password, and in
        // CI it has one. When a value is absent the child loads `.env` itself, like any request.
        $env = array_merge((array) getenv(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']);

        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $value = getenv($key);

            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        // `php -S` handles one connection at a time. A browser opens several (page, stylesheet,
        // preconnect), and on a fast runner the later ones time out and Chrome renders its own
        // error page instead of the site. Workers make the built-in server usable by a browser.
        // Honoured on POSIX only; Windows ignores it, where the tests run one connection at a time.
        if (DIRECTORY_SEPARATOR !== '\\') {
            $env['PHP_CLI_SERVER_WORKERS'] = '4';
        }

        // Output goes to a file, not to pipes. Pipes kept the parent process alive after the last
        // test: proc_close() waits for the child, and the child was still writing.
        self::$serverLog = dirname(__DIR__, 2) . '/storage/temp/e2e-server.log';
        @unlink(self::$serverLog);

        // On POSIX the shell is replaced by the server itself (`exec`), so terminating the handle
        // terminates the server. Without it the shell dies and `php -S` keeps running, which is how
        // the first version hung after the last test.
        $commandLine = implode(' ', array_map('escapeshellarg', $command));

        if (DIRECTORY_SEPARATOR !== '\\') {
            $commandLine = 'exec ' . $commandLine;
        }

        self::$serverProcess = proc_open(
            $commandLine,
            [1 => ['file', self::$serverLog, 'w'], 2 => ['file', self::$serverLog, 'a']],
            $pipes,
            $root,
            $env
        );

        // Wait for the port to answer before handing tests a browser.
        for ($attempt = 0; $attempt < 150; $attempt++) {
            $connection = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        // Say why, rather than a single message covering two different failures.
        $error = trim((string) @file_get_contents(self::$serverLog));

        self::$serverError = $error !== '' ? $error : 'the server did not open its port in five seconds';
        self::$serverPort = null;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            $status = proc_get_status(self::$serverProcess);

            // `proc_open` runs the command through the shell, so terminating the handle kills the
            // shell and leaves the server running. The tree has to go, or PHPUnit waits for it in
            // proc_close() and the whole run hangs after the last test.
            if (($status['pid'] ?? 0) > 0) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
                } else {
                    @exec('kill -TERM -' . (int) $status['pid'] . ' 2>/dev/null');
                }
            }

            proc_terminate(self::$serverProcess);
            @proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }

        self::$serverProcess = null;
        self::$serverPort = null;
    }

    /** The host the test site is resolvable at; the application maps sites by host, not by address. */
    private const SITE_HOST = 'example.com';

    protected function setUp(): void
    {
        parent::setUp();

        // One published article, so every path under test has data: a search with no results is a
        // themed 404, and asserting page structure against it tests the wrong page.
        $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'Hello E2E',
            'slug' => 'hello-e2e',
            'author_id' => $this->makeUser('e2e-author', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    private function url(string $path): string
    {
        return 'http://' . self::SITE_HOST . ':' . self::$serverPort . $path;
    }

    /**
     * The same URL addressed by IP, for fetches that cannot carry a Host header.
     *
     * Asset routes resolve their theme from the path, not from the host, so this is exact.
     */
    private function urlByAddress(string $path): string
    {
        return 'http://127.0.0.1:' . self::$serverPort . $path;
    }

    /**
     * Render a URL in headless Chrome and return the DOM after scripts ran.
     */
    private function render(string $path): string
    {
        if (self::$browser === '') {
            self::markTestSkipped('No headless browser found on this machine.');
        }

        if (self::$serverPort === null) {
            self::markTestSkipped('The application server did not start: ' . self::$serverError);
        }

        // `--virtual-time-budget` is required: without it this headless build dumps the DOM before
        // the page loads. It must also be long enough for a loaded runner — five seconds was not,
        // and the dump was Chrome's own error page rather than the site.
        // `--disable-dev-shm-usage` because containers often have a small /dev/shm.
        $command = escapeshellarg(self::$browser)
            . ' --headless=new --disable-gpu --no-sandbox --hide-scrollbars'
            . ' --disable-dev-shm-usage'
            . ' --virtual-time-budget=30000'
            // Resolve the site's own host to the local server, so the application sees the host it
            // expects and tenant resolution runs for real rather than being bypassed.
            . ' --host-resolver-rules=' . escapeshellarg('MAP ' . self::SITE_HOST . ' 127.0.0.1')
            . ' --dump-dom ' . escapeshellarg($this->url($path));

        // Warm the page with a plain HTTP request first, carrying the tenant's host.
        //
        // `/blog` was the one path that intermittently failed in CI: the browser's request for a
        // cache-miss listing did not complete inside the virtual time budget, twice, while `/` and
        // `/search` were fine and the server stayed healthy. What this suite tests is the rendered
        // structure, not the first-hit latency, so the page is rendered warm — which is also the
        // state a returning visitor sees.
        $context = stream_context_create([
            'http' => ['header' => 'Host: ' . self::SITE_HOST . "\r\n", 'timeout' => 20],
        ]);

        @file_get_contents($this->urlByAddress($path), false, $context);

        $dom = '';

        // Two attempts. Chrome renders its own error page when the request does not complete inside
        // the virtual time budget, and on a loaded runner that happens intermittently even though
        // the server is healthy: run #15 of this project's CI was green and run #16, on identical
        // code, was red.
        //
        // This retries a *transport* failure, never an assertion. The assertions below run once,
        // against whatever the browser finally rendered.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $output = [];
            $status = 0;
            // No shell redirection: 2>/dev/null is POSIX and silently breaks the command on
            // Windows, which is how the first version produced an empty DOM.
            exec($command, $output, $status);

            $dom = implode("\n", $output);

            if (!str_contains($dom, 'An unexpected error occurred')) {
                break;
            }

            usleep(500_000);
        }

        // Chrome renders its own error page when it cannot talk to the server. Saying so, with the
        // server's own log, is the difference between a five-minute fix and an afternoon.
        if (str_contains($dom, 'An unexpected error occurred')) {
            $log = trim((string) @file_get_contents(self::$serverLog));

            self::fail(
                'The browser could not load ' . $this->url($path) . ' in two attempts. Server log:'
                . "\n" . (substr($log, -1200) ?: '(empty)')
            );
        }

        self::assertNotSame('', trim($dom), 'The browser produced no DOM for ' . $path);

        return $dom;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicPaths(): array
    {
        return [
            'home' => ['/'],
            'blog archive' => ['/blog'],
            'search' => ['/search?q=hello'],
        ];
    }

    #[Test]
    #[DataProvider('publicPaths')]
    public function aPublicPageRendersAsRealHtml(string $path): void
    {
        $dom = $this->render($path);

        // Structure a screen reader and a browser both rely on.
        self::assertMatchesRegularExpression('#<html[^>]*lang="[a-z]{2}#i', $dom, 'The document declares a language.');
        self::assertStringContainsString('<main', $dom, 'There is a main landmark.');
        self::assertMatchesRegularExpression('#<title>[^<]+</title>#i', $dom, 'The document has a title.');

        // No PHP leaking into markup: the classic symptom of a broken include or a fatal error.
        foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:'] as $leak) {
            self::assertStringNotContainsString($leak, $dom, 'The page must not leak PHP diagnostics.');
        }

        // The stylesheet really resolves: an unstyled public site passed every string test once.
        preg_match_all('#<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"#i', $dom, $matches);

        self::assertNotEmpty($matches[1] ?? [], 'The page links a stylesheet.');

        foreach ($matches[1] as $href) {
            // Absolute in the markup (the site's base URL), fetched by address because a plain
            // file_get_contents cannot set a Host header.
            $path = parse_url($href, PHP_URL_PATH) ?: $href;
            $body = @file_get_contents($this->urlByAddress($path));

            self::assertNotFalse($body, 'Stylesheet must be reachable: ' . $href);
            self::assertNotSame('', trim((string) $body), 'Stylesheet must not be empty: ' . $href);
        }
    }

    #[Test]
    public function everyImageHasAltTextAndEveryControlALabel(): void
    {
        $dom = $this->render('/blog');

        preg_match_all('#<img\b[^>]*>#i', $dom, $images);

        foreach ($images[0] ?? [] as $image) {
            self::assertMatchesRegularExpression('#\balt=#i', $image, 'Every image needs an alt attribute: ' . $image);
        }

        preg_match_all('#<input\b[^>]*>#i', $dom, $inputs);

        foreach ($inputs[0] ?? [] as $input) {
            if (preg_match('#type="(hidden|submit|checkbox|radio)"#i', $input) === 1) {
                continue;
            }

            // Either wrapped in a label or referenced by one through an id.
            preg_match('#id="([^"]+)"#i', $input, $idMatch);
            $labelled = $idMatch !== [] && str_contains($dom, 'for="' . $idMatch[1] . '"');

            self::assertTrue($labelled, 'A visible input needs a label: ' . $input);
        }
    }

    #[Test]
    public function anArticlePageRendersItsContentAndThread(): void
    {
        $this->article('browser-article', 'Browser Article');

        $dom = $this->render('/blog/browser-article');

        self::assertStringContainsString('Browser Article', $dom);
        self::assertStringContainsString('<article', $dom);
        self::assertStringContainsString('Leave a comment', $dom, 'The comment form is present and reachable.');
    }

    #[Test]
    public function theAdminLoginScreenRendersAndIsStyled(): void
    {
        $dom = $this->render('/admin/login');

        self::assertStringContainsString('name="identifier"', $dom);
        self::assertStringContainsString('name="password"', $dom);
        self::assertStringContainsString('<label', $dom, 'The sign-in fields are labelled.');

        // The admin CSS is served through a route, so a broken binding shows up here.
        preg_match('#<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"#i', $dom, $match);
        self::assertNotEmpty($match[1] ?? '', 'The admin shell links its stylesheet.');

        $adminCss = parse_url($match[1], PHP_URL_PATH) ?: $match[1];
        self::assertNotFalse(@file_get_contents($this->urlByAddress($adminCss)), 'The admin stylesheet resolves.');
    }

    private function article(string $slug, string $title): void
    {
        $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => $title,
            'slug' => $slug,
            'author_id' => $this->makeUser('e2e-writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);
    }

    private static function findBrowser(): string
    {
        foreach ([
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
