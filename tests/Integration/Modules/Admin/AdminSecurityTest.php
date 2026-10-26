<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The security properties of the admin surface.
 *
 * Each case is a risk from the design document, pinned so a later change cannot quietly remove
 * the mitigation.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Middleware\AdminAuthMiddleware::class)]
#[CoversClass(\CMS\Modules\Admin\Http\Controller\ContentController::class)]
final class AdminSecurityTest extends AdminTestCase
{
    #[Test]
    public function everyAdminPageIsMarkedNoStore(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        foreach (['/admin', '/admin/content', '/admin/content/new'] as $path) {
            $response = $this->get($path);

            self::assertSame(200, $response->status(), $path);

            // Without no-store a shared machine exposes one person's drafts to the next.
            self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'), $path);
            self::assertSame('DENY', $response->header('X-Frame-Options'), $path);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            self::assertSame('same-origin', $response->header('Referrer-Policy'), $path);
        }
    }

    #[Test]
    public function aMutationWithoutATokenIsRefused(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $this->loginAs($contributor);

        $response = $this->post('/admin/content', ['title' => 'No Token Here'], false);

        self::assertSame(403, $response->status());

        // Nothing was written.
        $page = $this->body($this->get('/admin/content'));
        self::assertStringNotContainsString('No Token Here', $page);
    }

    #[Test]
    public function aMutationWithAForgedTokenIsRefused(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        $response = $this->post('/admin/content', [
            'title' => 'Forged Token',
            '_token' => str_repeat('a', 64),
        ], false);

        self::assertSame(403, $response->status());
    }

    #[Test]
    public function aTitleContainingMarkupIsEscapedEverywhere(): void
    {
        $contributor = $this->makeUser('writer', 'contributor');
        $this->loginAs($contributor);

        $payload = '<script>alert("xss")</script>';

        $created = $this->post('/admin/content', [
            'title' => $payload,
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body.']]],
        ]);

        self::assertSame(303, $created->status());

        $id = (int) $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('script-alert-xss-script', 'post', $this->siteId)->id;

        foreach (['/admin/content', '/admin/content/' . $id] as $path) {
            $html = $this->body($this->get($path));

            self::assertStringNotContainsString('<script>alert', $html, $path . ' must escape the title.');
            self::assertStringContainsString('&lt;script&gt;', $html, $path . ' should show the text, escaped.');
        }
    }

    #[Test]
    public function blockContentIsStoredAsDataAndNotAsHtml(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        // A block's text is data. If the compiler ever stopped escaping, this is the test that
        // would notice, because the stored json must not contain a live tag.
        $this->post('/admin/content', [
            'title' => 'Injection Attempt',
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => '<img src=x onerror=alert(1)>']],
            ],
        ]);

        $content = $this->app->container()->get(ContentServiceInterface::class)
            ->getBySlug('injection-attempt', 'post', $this->siteId);

        self::assertStringContainsString('onerror', (string) $content->contentJson);
        self::assertStringNotContainsString('<img', (string) $content->contentHtml);
        self::assertStringContainsString('&lt;img', (string) $content->contentHtml);
    }

    #[Test]
    public function anAnonymousVisitorCannotReachAnyAdminPage(): void
    {
        // Covered per route in AdminAuthTest; this asserts the middleware is on the whole
        // surface, including a POST, which returns a redirect rather than a 401 form.
        self::assertSame(303, $this->post('/admin/content', ['title' => 'Anon'])->status());
        self::assertSame(303, $this->post('/admin/logout')->status());
    }

    #[Test]
    public function aFailedCsrfCheckStillCarriesTheSecurityHeaders(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        $response = $this->post('/admin/content', ['title' => 'No Token'], false);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
    }
}
