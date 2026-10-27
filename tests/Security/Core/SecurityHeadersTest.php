<?php

declare(strict_types=1);

namespace Tests\Security\Core;

use CMS\Core\Http\Middleware\SecurityHeadersMiddleware;
use CMS\Core\Http\Request;
use CMS\Shared\Contracts\ConfigInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Security headers, and the reason the policy can be strict.
 *
 * A Content-Security-Policy is only worth having if `script-src` excludes `'unsafe-inline'`. That
 * requires removing every inline event handler, which is why the admin confirm dialogs moved to
 * `public/assets/admin.js` with a `data-confirm` attribute.
 *
 * The tests below check three different things:
 *
 *  1. the headers are present on a page, an error, an admin screen and the API;
 *  2. the policy says what it claims to say;
 *  3. the rendered HTML actually contains no inline handlers or inline scripts, so the policy
 *     cannot be quietly invalidated by a future view.
 */
#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersTest extends AdminTestCase
{
    private function fetch(string $path): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host));
    }

    private function csp(\CMS\Core\Http\Response $response): string
    {
        return (string) $response->header('Content-Security-Policy');
    }

    #[Test]
    public function aPublicPageCarriesTheHeaders(): void
    {
        $response = $this->fetch('/');
        $policy = $this->csp($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        self::assertStringContainsString('camera=()', (string) $response->header('Permissions-Policy'));
    }

    #[Test]
    public function thePolicyForbidsInlineScript(): void
    {
        $policy = $this->csp($this->fetch('/'));

        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringNotContainsString(
            "script-src 'self' 'unsafe-inline'",
            $policy,
            'Inline script must stay disallowed by default.'
        );
    }

    #[Test]
    public function anErrorPageCarriesTheHeaders(): void
    {
        $response = $this->fetch('/this-page-does-not-exist');

        self::assertSame(404, $response->status());
        self::assertNotSame('', $this->csp($response), 'A 404 is probed more often than a page.');
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    #[Test]
    public function anAdminScreenCarriesTheHeaders(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->fetch('/admin');
        $policy = $this->csp($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertSame('DENY', $response->header('X-Frame-Options'));
    }

    #[Test]
    public function theApiCarriesTheHeaders(): void
    {
        $response = $this->fetch('/api/v1/health');

        self::assertSame(200, $response->status());
        self::assertNotSame('', $this->csp($response));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    #[Test]
    public function theRenderedPagesHaveNoInlineHandlersOrScripts(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $pages = ['/', '/admin', '/admin/ads', '/admin/plugins', '/admin/users', '/admin/redirects'];

        foreach ($pages as $page) {
            $html = ($this->fetch($page))->content();

            self::assertDoesNotMatchRegularExpression(
                '/\son[a-z]+\s*=/i',
                $html,
                $page . ' still contains an inline event handler, which the CSP would block.'
            );

            preg_match_all('/<script\b([^>]*)>/i', $html, $matches);

            foreach ($matches[1] as $attributes) {
                self::assertMatchesRegularExpression(
                    '/\bsrc\s*=/i',
                    $attributes,
                    $page . ' contains an inline <script> block; the CSP would block it.'
                );
            }
        }
    }

    #[Test]
    public function theAdminShellLoadsItsBehaviourAsAFile(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $html = ($this->fetch('/admin'))->content();

        self::assertStringContainsString('src="/assets/admin.js"', $html);
        $this->app->container()->get(\CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface::class)->create(
            $this->siteId,
            ['name' => 'Header ad', 'placement' => 'header', 'body_html' => '<b>Ad</b>', 'is_active' => true]
        );

        self::assertStringContainsString('data-confirm', ($this->fetch('/admin/ads'))->content());
    }

    #[Test]
    public function inlineScriptCanBeAllowedExplicitly(): void
    {
        $config = $this->app->container()->get(ConfigInterface::class);

        // The default is strict; an operator who installs an inline analytics snippet flips this,
        // and the document says so. style-src carries 'unsafe-inline' in every policy, so the
        // assertion is on the script-src directive specifically.
        $defaultScriptSrc = (new SecurityHeadersMiddleware())->policy();
        preg_match('/script-src [^;]+/', $defaultScriptSrc, $match);

        self::assertSame("script-src 'self'", trim($match[0] ?? ''), 'The default policy must not allow inline script.');

        self::assertStringContainsString(
            "script-src 'self' 'unsafe-inline'",
            (new SecurityHeadersMiddleware(true))->policy(),
            'The opt-in must be visible in the policy, not silent.'
        );
    }
}
