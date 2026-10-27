<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The redirects screen.
 *
 * The service, its validation and the middleware that applies redirects already existed; only the
 * screen was missing, so a redirect could only be added through the API or SQL.
 *
 * Validation is not re-implemented here: the tests assert that the service's rules reach the person
 * as a message, and that nothing is stored when it refuses.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\RedirectController::class)]
final class AdminRedirectTest extends AdminTestCase
{
    private function redirects(): RedirectServiceInterface
    {
        return $this->app->container()->get(RedirectServiceInterface::class);
    }

    #[Test]
    public function theScreenRequiresTheSettingsCapability(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        // An editor publishes; changing where a public URL sends visitors is configuration.
        self::assertSame(403, $this->get('/admin/redirects')->status());
        self::assertSame(403, $this->post('/admin/redirects', ['source_path' => '/a', 'target_url' => '/b'])->status());
    }

    #[Test]
    public function anAdministratorCanAddAndSeeARedirect(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/redirects', [
            'source_path' => '/old-news',
            'target_url' => '/blog/new-news',
            'status_code' => '301',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin/redirects', $this->location($response));

        $resolved = $this->redirects()->resolve($this->siteId, '/old-news');
        self::assertNotNull($resolved);
        self::assertSame('/blog/new-news', $resolved['target_url']);
        self::assertSame(301, (int) $resolved['status_code']);

        $html = $this->body($this->get('/admin/redirects'));
        self::assertStringContainsString('/old-news', $html);
        self::assertStringContainsString('/blog/new-news', $html);
    }

    #[Test]
    public function aProtocolRelativeTargetIsRefused(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        // `//evil.example` is an off-site redirect in disguise.
        $response = $this->post('/admin/redirects', [
            'source_path' => '/phish',
            'target_url' => '//evil.example/steal',
        ]);

        self::assertSame(422, $response->status());
        self::assertNotNull($this->body($response));
        self::assertNull($this->redirects()->resolve($this->siteId, '/phish'));
    }

    #[Test]
    public function theSiteRootCannotBeRedirected(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/redirects', [
            'source_path' => '/',
            'target_url' => '/somewhere',
        ]);

        self::assertSame(422, $response->status());
        self::assertNull($this->redirects()->resolve($this->siteId, '/'));
    }

    #[Test]
    public function aSelfRedirectIsRefused(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/redirects', [
            'source_path' => '/loop',
            'target_url' => '/loop',
        ]);

        self::assertSame(422, $response->status());
        self::assertNull($this->redirects()->resolve($this->siteId, '/loop'));
    }

    #[Test]
    public function anUnsupportedStatusIsRefused(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/redirects', [
            'source_path' => '/odd',
            'target_url' => '/somewhere',
            'status_code' => '999',
        ]);

        self::assertSame(422, $response->status());
        self::assertNull($this->redirects()->resolve($this->siteId, '/odd'));
    }

    #[Test]
    public function aRedirectCanBeDeleted(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $this->post('/admin/redirects', ['source_path' => '/gone', 'target_url' => '/kept']);
        self::assertNotNull($this->redirects()->resolve($this->siteId, '/gone'));

        $response = $this->post('/admin/redirects/delete', ['source_path' => '/gone']);

        self::assertSame(303, $response->status());
        self::assertNull($this->redirects()->resolve($this->siteId, '/gone'));
    }

    #[Test]
    public function addingARedirectNeedsACsrfToken(): void
    {
        $this->loginAs($this->makeUser('admin', 'administrator'));

        $response = $this->post('/admin/redirects', [
            'source_path' => '/no-token',
            'target_url' => '/somewhere',
        ], false);

        self::assertSame(403, $response->status());
        self::assertNull($this->redirects()->resolve($this->siteId, '/no-token'));
    }

    #[Test]
    public function redirectsDoNotCrossTenants(): void
    {
        $otherSite = $this->createSiteRow('other', 'other.example');

        $this->redirects()->store($otherSite, '/theirs', '/their-target');

        $this->loginAs($this->makeUser('admin', 'administrator'));

        $html = $this->body($this->get('/admin/redirects'));

        self::assertStringNotContainsString('/theirs', $html);
        self::assertNull($this->redirects()->resolve($this->siteId, '/theirs'));
    }

    #[Test]
    public function theScreenIsOnlyInTheMenuForSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));
        self::assertStringNotContainsString('href="/admin/redirects"', $this->body($this->get('/admin')));

        $this->loginAs($this->makeUser('admin', 'administrator'));
        self::assertStringContainsString('href="/admin/redirects"', $this->body($this->get('/admin')));
    }
}
