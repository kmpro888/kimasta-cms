<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Core\Http\Request;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Creating a site, and reaching it.
 *
 * Creating a tenant is not an insert: the domain row, the compiled domain map, the role catalogue
 * and the taxonomy defaults all have to exist, and a site nobody can sign in to is not usable. The
 * tests assert that the site is reachable through its own host, and that its content is its own.
 *
 * There is no site switcher, and this is the test that documents why: the first administrator of the
 * new site signs in at the new domain, because accounts belong to one site.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\SiteController::class)]
final class AdminSiteTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function newSiteFields(string $slug = 'second', string $domain = 'second.example'): array
    {
        return [
            'name' => 'Second Site',
            'slug' => $slug,
            'domain' => $domain,
            'default_locale' => 'id',
            'timezone' => 'Asia/Jakarta',
            'admin_username' => 'secondadmin',
            'admin_email' => 'secondadmin@example.test',
            'admin_password' => 'a-real-password',
        ];
    }

    #[Test]
    public function theScreenRequiresTheSettingsCapability(): void
    {
        $this->loginAs($this->makeUser('boss', 'editor'));

        self::assertSame(403, $this->get('/admin/sites')->status());
        self::assertSame(403, $this->post('/admin/sites', $this->newSiteFields())->status());
    }

    #[Test]
    public function aSiteIsCreatedWithItsAdministratorAndIsReachableAtItsOwnDomain(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $response = $this->post('/admin/sites', $this->newSiteFields());

        self::assertSame(303, $response->status());
        self::assertSame('/admin/sites', $this->location($response));

        $site = $this->app->container()->get(SiteServiceInterface::class)->findBySlug('second');

        self::assertSame('Second Site', $site->name());
        self::assertSame('second.example', $site->host());
        self::assertContains('second.example', $this->app->container()->get(SiteServiceInterface::class)->domains($site->id()));

        // The role catalogue was seeded, so the new administrator really has the role.
        $roles = $this->app->container()->get(RoleServiceInterface::class);

        self::assertNotEmpty($roles->listForSite($site->id()));

        // And the person can sign in — at the new host, which is the only way to reach it.
        $loginPage = $this->app->handle(Request::create('GET', '/admin/login', 'second.example'));
        self::assertSame(200, $loginPage->status(), 'The new site must answer on its own host.');

        preg_match('/name="_token" value="([^"]+)"/', $loginPage->content(), $match);

        $login = $this->app->handle(Request::create('POST', '/admin/login', 'second.example', [], [
            'identifier' => 'secondadmin',
            'password' => 'a-real-password',
            '_token' => $match[1] ?? '',
        ]));

        self::assertSame(303, $login->status());
        self::assertSame('/admin', $this->location($login));
    }

    #[Test]
    public function theNewSiteIsIsolatedFromTheFirst(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => 'post',
            'title' => 'First site secret',
            'slug' => 'first-site-secret',
            'author_id' => $this->makeUser('writer', 'contributor'),
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body.']]],
        ]);

        $this->post('/admin/sites', $this->newSiteFields());

        // An unauthenticated visitor to the second site's admin is redirected to its own sign-in:
        // the session from the first site does not carry over, which is the isolation itself.
        $response = $this->app->handle(Request::create('GET', '/admin/content', 'second.example'));

        self::assertSame(303, $response->status());
        self::assertStringContainsString('/admin/login', (string) $response->header('Location'));
        self::assertStringNotContainsString('First site secret', $response->content());
    }

    #[Test]
    public function aDuplicateDomainIsRefused(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        // `example.com` is the site the tests run as.
        $response = $this->post('/admin/sites', $this->newSiteFields('third', 'example.com'));

        self::assertSame(422, $response->status());
        self::assertSame(1, count($this->app->container()->get(SiteServiceInterface::class)->all()));
    }

    #[Test]
    public function aDuplicateSlugIsRefusedAsAFormErrorNotAServerError(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        // The slug of the site the tests are running as: guaranteed to be taken.
        $taken = $this->app->container()->get(SiteServiceInterface::class)->findById($this->siteId)->slug();
        $fields = $this->newSiteFields($taken, 'another.example');
        $response = $this->post('/admin/sites', $fields);

        self::assertSame(422, $response->status(), 'A duplicate slug is a form error, not a 500.');
        self::assertSame(1, count($this->app->container()->get(SiteServiceInterface::class)->all()));
    }
    #[Test]
    public function aMalformedSlugOrAShortPasswordIsRefusedBeforeAnythingIsCreated(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $badSlug = $this->post('/admin/sites', $this->newSiteFields('Not A Slug'));
        self::assertSame(422, $badSlug->status());

        $fields = $this->newSiteFields();
        $fields['admin_password'] = 'short';
        $badPassword = $this->post('/admin/sites', $fields);

        self::assertSame(422, $badPassword->status());
        self::assertSame(1, count($this->app->container()->get(SiteServiceInterface::class)->all()));
    }

    #[Test]
    public function anAliasDomainCanBeAddedAndRemovedButThePrimaryCannot(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));
        $this->post('/admin/sites', $this->newSiteFields());

        $site = $this->app->container()->get(SiteServiceInterface::class)->findBySlug('second');

        $added = $this->post('/admin/sites/domain', [
            'site_id' => (int) $site->id(),
            'domain' => 'www.second.example',
        ]);

        self::assertSame(303, $added->status());
        self::assertContains('www.second.example', $this->app->container()->get(SiteServiceInterface::class)->domains((int) $site->id()));

        // The alias reaches the same site.
        self::assertSame(200, $this->app->handle(Request::create('GET', '/admin/login', 'www.second.example'))->status());

        $removed = $this->post('/admin/sites/domain/remove', [
            'site_id' => (int) $site->id(),
            'domain' => 'www.second.example',
        ]);

        self::assertSame(303, $removed->status());
        self::assertNotContains('www.second.example', $this->app->container()->get(SiteServiceInterface::class)->domains((int) $site->id()));

        // The primary cannot be removed: it is how the site is reached at all.
        $this->post('/admin/sites/domain/remove', [
            'site_id' => (int) $site->id(),
            'domain' => 'second.example',
        ]);

        self::assertContains('second.example', $this->app->container()->get(SiteServiceInterface::class)->domains((int) $site->id()));
    }

    #[Test]
    public function theSitesEntryIsOnlyShownToSomeoneWhoCanUseIt(): void
    {
        $this->loginAs($this->makeUser('ed', 'editor'));
        self::assertStringNotContainsString('href="/admin/sites"', $this->body($this->get('/admin')));

        $this->loginAs($this->makeUser('boss', 'administrator'));
        self::assertStringContainsString('href="/admin/sites"', $this->body($this->get('/admin')));
    }
}
