<?php

declare(strict_types=1);

namespace Tests\Accessibility;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * An accessibility audit that runs against the rendered pages.
 *
 * No axe-core: that needs Node, and this project must not require it. These are the checks that can
 * be made mechanically and without ambiguity from the DOM — the ones that catch the failures
 * people actually ship: an unlabelled input, an image with no alt, a heading level skipped, a link
 * whose only content is an icon, an `aria-labelledby` pointing at nothing.
 *
 * What it cannot check is stated in the report rather than pretended: colour contrast, focus
 * visibility, screen-reader phrasing and keyboard traps need a person or a different tool.
 */
final class AccessibilityAuditTest extends AdminTestCase
{
    private const SITE_HOST = 'example.com';

    private static ?int $port = null;
    private static $process = null;
    private static string $log = '';
    private static string $cookieJar = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);

        $root = dirname(__DIR__, 2);
        self::$log = $root . '/storage/temp/a11y-server.log';
        self::$cookieJar = $root . '/storage/temp/a11y-cookies.txt';
        @unlink(self::$log);
        @unlink(self::$cookieJar);

        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-S', '127.0.0.1:' . self::$port,
            '-t', $root . '/public',
            $root . '/tests/E2E/router.php',
        ]));

        if (DIRECTORY_SEPARATOR !== '\\') {
            $command = 'exec ' . $command;
        }

        $env = array_merge((array) getenv(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']);

        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $value = getenv($key);

            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $env['PHP_CLI_SERVER_WORKERS'] = '4';
        }

        self::$process = proc_open(
            $command,
            [1 => ['file', self::$log, 'w'], 2 => ['file', self::$log, 'a']],
            $pipes,
            $root,
            $env
        );

        for ($attempt = 0; $attempt < 150; $attempt++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::$port = null;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            $status = proc_get_status(self::$process);

            if (($status['pid'] ?? 0) > 0) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
                } else {
                    @exec('kill -TERM -' . (int) $status['pid'] . ' 2>/dev/null');
                }
            }

            proc_terminate(self::$process);
            @proc_close(self::$process);
            self::$process = null;
        }

        @unlink(self::$cookieJar);
    }

    /**
     * Fetch a page over HTTP, carrying the session cookie when asked.
     */
    private function fetch(string $path, bool $authenticated = false): string
    {
        if (self::$port === null) {
            self::markTestSkipped('The application server did not start.');
        }

        $handle = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . self::SITE_HOST],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEJAR => self::$cookieJar,
            CURLOPT_COOKIEFILE => $authenticated ? self::$cookieJar : '',
        ]);

        $body = (string) curl_exec($handle);
        curl_close($handle);

        return $body;
    }

    private function signIn(): void
    {
        if (self::$port === null) {
            self::markTestSkipped('The application server did not start.');
        }

        $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($this->siteId, [
                'username' => 'a11yadmin',
                'email' => 'a11y@example.test',
                'password' => 'correct-horse-battery',
                'display_name' => 'Audit Admin',
            ]);

        $adminRole = 0;

        foreach ($this->app->container()->get(\CMS\Modules\Identity\Contracts\RoleServiceInterface::class)
            ->listForSite($this->siteId) as $role) {
            if ($role->slug === 'administrator') {
                $adminRole = (int) $role->id;
            }
        }

        foreach ($this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->listForSite($this->siteId) as $user) {
            if ($user->username === 'a11yadmin') {
                $this->app->container()->get(\CMS\Modules\Identity\Contracts\RoleServiceInterface::class)
                    ->assignRole((int) $user->id, $adminRole, $this->siteId);
            }
        }

        $loginPage = $this->fetch('/admin/login');
        preg_match('/name="_token" value="([^"]+)"/', $loginPage, $match);
        $token = $match[1] ?? '';

        self::assertNotSame('', $token, 'The sign-in form must carry a CSRF token.');

        $handle = curl_init('http://127.0.0.1:' . self::$port . '/admin/login');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . self::SITE_HOST],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEJAR => self::$cookieJar,
            CURLOPT_COOKIEFILE => self::$cookieJar,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'identifier' => 'a11yadmin',
                'password' => 'correct-horse-battery',
                '_token' => $token,
            ]),
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        self::assertSame(303, $status, 'The audit must be able to sign in.');
    }

    /**
     * @return list<string> human-readable violations
     */
    private function audit(string $html, string $page): array
    {
        $violations = [];

        if (trim($html) === '') {
            return [$page . ': empty response'];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $add = static function (string $check, string $detail) use (&$violations, $page): void {
            $violations[] = $page . ' [' . $check . '] ' . $detail;
        };

        // Document identity.
        $htmlNode = $xpath->query('//html')->item(0);

        if (!$htmlNode instanceof DOMElement || trim($htmlNode->getAttribute('lang')) === '') {
            $add('lang', 'the document element has no lang attribute');
        }

        if (trim((string) $document->getElementsByTagName('title')->item(0)?->textContent) === '') {
            $add('title', 'the document has no title');
        }

        // One h1, and a heading order that does not skip.
        $headings = [];

        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $headings[] = (int) substr($heading->nodeName, 1);
        }

        $h1Count = count(array_keys($headings, 1));

        if ($h1Count !== 1) {
            $add('h1', 'expected exactly one h1, found ' . $h1Count);
        }

        $previousLevel = 0;

        foreach ($headings as $level) {
            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                $add('heading-order', 'h' . $previousLevel . ' is followed by h' . $level);
            }

            $previousLevel = $level;
        }

        // Landmarks: one main region, and a navigation in the admin shell (the sign-in page has no
        // menu to expose, so it is exempt).
        $mainCount = $xpath->query('//main')->length;

        if ($mainCount !== 1) {
            $add('main-landmark', 'expected exactly one <main>, found ' . $mainCount);
        }

        if (str_starts_with($page, '/admin') && $page !== '/admin/login' && $xpath->query('//nav')->length === 0) {
            $add('nav-landmark', 'the admin shell has no <nav>');
        }
        // Images need an alt attribute; empty is a valid choice for decoration, absent is not.
        foreach ($xpath->query('//img') as $image) {
            if (!$image->hasAttribute('alt')) {
                $add('img-alt', 'an image has no alt attribute: ' . $image->getAttribute('src'));
            }
        }

        // Every visible control needs an accessible name.
        foreach ($xpath->query('//input|//select|//textarea') as $control) {
            $type = strtolower($control->getAttribute('type'));

            if (in_array($type, ['hidden', 'submit', 'button', 'reset'], true)) {
                continue;
            }

            if (!$this->hasAccessibleName($xpath, $control)) {
                $add('control-name', 'a ' . ($control->nodeName) . ' has no label: ' . $control->getAttribute('name'));
            }
        }

        // A link or button whose only content is an icon is invisible to a screen reader.
        foreach ($xpath->query('//a|//button') as $element) {
            $text = trim((string) $element->textContent);

            if ($text !== '') {
                continue;
            }

            if (trim($element->getAttribute('aria-label')) !== '') {
                continue;
            }

            if (trim($element->getAttribute('aria-labelledby')) !== '') {
                continue;
            }

            if ($element->getElementsByTagName('img')->length > 0) {
                continue;
            }

            $add('accessible-name', 'a ' . $element->nodeName . ' has no text and no aria-label');
        }

        // aria-labelledby / aria-describedby must point at something that exists.
        foreach (['aria-labelledby', 'aria-describedby'] as $attribute) {
            foreach ($xpath->query('//*[@' . $attribute . ']') as $element) {
                foreach (preg_split('/\s+/', trim($element->getAttribute($attribute))) ?: [] as $id) {
                    if ($id !== '' && $xpath->query('//*[@id="' . $id . '"]')->length === 0) {
                        $add($attribute, 'points at "' . $id . '", which does not exist');
                    }
                }
            }
        }

        // Duplicate ids break label and aria references.
        $seen = [];

        foreach ($xpath->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');

            if (isset($seen[$id])) {
                $add('duplicate-id', 'id "' . $id . '" appears more than once');
            }

            $seen[$id] = true;
        }

        // A positive tabindex reorders focus against reading order.
        foreach ($xpath->query('//*[@tabindex]') as $element) {
            if ((int) $element->getAttribute('tabindex') > 0) {
                $add('tabindex', 'positive tabindex on ' . $element->nodeName);
            }
        }

        // Tables need a header row.
        foreach ($xpath->query('//table') as $table) {
            if ($xpath->query('.//th', $table)->length === 0) {
                $add('table-headers', 'a table has no th cells');
            }
        }

        // A skip link, when present, must point at something.
        $skip = $xpath->query('//a[starts-with(@href, "#")]');

        foreach ($skip as $link) {
            $target = substr($link->getAttribute('href'), 1);

            if ($target !== '' && $xpath->query('//*[@id="' . $target . '"]')->length === 0) {
                $add('skip-link', 'in-page link points at #' . $target . ', which does not exist');
            }
        }

        return $violations;
    }

    private function hasAccessibleName(DOMXPath $xpath, DOMElement $control): bool
    {
        $id = $control->getAttribute('id');

        if ($id !== '' && $xpath->query('//label[@for="' . $id . '"]')->length > 0) {
            return true;
        }

        // Wrapped in a label.
        for ($node = $control->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->nodeName) === 'label') {
                return true;
            }
        }

        // placeholder is deliberately not accepted: it disappears on input and is not a label.
        foreach (['aria-label', 'aria-labelledby', 'title'] as $attribute) {
            if (trim($control->getAttribute($attribute)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function publicPages(): array
    {
        return [
            'home' => ['/', false],
            'blog archive' => ['/blog', false],
            'search' => ['/search?q=audit', false],
            'missing page' => ['/no-such-page-here', false],
        ];
    }

    #[Test]
    #[DataProvider('publicPages')]
    public function aPublicPageHasNoMechanicalAccessibilityViolations(string $path, bool $authenticated): void
    {
        $this->article();
        $html = $this->fetch($path, $authenticated);

        self::assertSame([], $this->audit($html, $path));
    }

    #[Test]
    public function anArticlePageHasNoMechanicalAccessibilityViolations(): void
    {
        $slug = $this->article();

        self::assertSame([], $this->audit($this->fetch('/blog/' . $slug), '/blog/' . $slug));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminPages(): array
    {
        return [
            'sign in' => ['/admin/login'],
            'dashboard' => ['/admin'],
            'content list' => ['/admin/content'],
            'media' => ['/admin/media'],
            'notifications' => ['/admin/notifications'],
            'notification settings' => ['/admin/notifications/preferences'],
            'redirects' => ['/admin/redirects'],
            'plugins' => ['/admin/plugins'],
            'ads' => ['/admin/ads'],
            'settings' => ['/admin/settings'],
            'people' => ['/admin/users'],
            'comments' => ['/admin/comments'],
        ];
    }

    #[Test]
    #[DataProvider('adminPages')]
    public function anAdminPageHasNoMechanicalAccessibilityViolations(string $path): void
    {
        if ($path !== '/admin/login') {
            $this->signIn();
        }

        $html = $this->fetch($path, $path !== '/admin/login');

        self::assertStringNotContainsString('Access denied', $html, 'The audit must reach the page, not a 403.');
        self::assertSame([], $this->audit($html, $path));
    }

    private function article(): string
    {
        $slug = 'a11y-article';

        try {
            $this->app->container()->get(\CMS\Modules\Content\Contracts\ContentServiceInterface::class)
                ->create($this->siteId, [
                    'type' => 'post',
                    'title' => 'Accessibility Article',
                    'slug' => $slug,
                    'excerpt' => 'A summary.',
                    'author_id' => $this->makeUser('a11ywriter', 'contributor'),
                    'status' => 'published',
                    'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
                ]);
        } catch (\Throwable) {
            // Already created by an earlier test in this class.
        }

        return $slug;
    }
}
