<?php

declare(strict_types=1);

namespace Tests\Integration\EndToEnd;

use CMS\Core\Http\Request;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * The multisite end-to-end test from the task pack (section 8).
 *
 * Two tenants serve the same slug `/news`. Everything must stay apart: content, users,
 * theme, plugins, cache, SEO and media. The interesting failures in a multisite CMS are
 * never "it does not work" — they are "it works, for the wrong tenant".
 */
final class MultisiteIsolationTest extends ApiTestCase
{
    private string $hostA = 'example.com';

    private string $hostB = 'other.example';

    private int $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        // Site A is created by the harness (example.com). Site B is ours.
        $this->siteB = $this->createSiteRow('other', $this->hostB);

        // The compiled domain map is what tenant resolution reads, so it has to know B.
        $this->app->container()->get(SiteServiceInterface::class)->regenerateDomainMap();
    }

    private function publishPage(int $siteId, string $title, string $body, int $authorId): int
    {
        $content = $this->app->container()->get(ContentServiceInterface::class)->create($siteId, [
            'type' => 'page',
            'title' => $title,
            'slug' => 'news',
            'author_id' => $authorId,
            'status' => 'published',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => $body]]],
        ]);

        return (int) $content->id;
    }

    private function read(string $host, string $path = '/news'): array
    {
        $response = $this->app->handle(Request::create('GET', $path, $host));

        return [
            'status' => $response->status(),
            'body' => $response->content(),
            'cache' => $response->header('X-Cache'),
        ];
    }

    #[Test]
    public function thesameSlugOnTwoTenantsServesDifferentContent(): void
    {
        $authorA = $this->makeUser('authorA');
        $authorB = (int) $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($this->siteB, [
                'username' => 'authorB',
                'email' => 'authorB@example.com',
                'password' => 'correct-horse-battery',
                'display_name' => 'Author B',
            ])->id;

        $contentA = $this->publishPage($this->siteId, 'News from A', 'Body belonging to tenant A.', $authorA);
        $contentB = $this->publishPage($this->siteB, 'News from B', 'Body belonging to tenant B.', $authorB);

        // Same slug, different rows.
        self::assertNotSame($contentA, $contentB);

        // ---- content isolation -------------------------------------------------
        $this->loginAs($authorA);

        $crossRead = $this->call('GET', '/api/v1/content/' . $contentB);
        self::assertSame(404, $crossRead->status(), 'Cross-tenant content must be a 404, not a 403.');
        self::assertStringNotContainsString('News from B', $crossRead->content());

        // ---- public rendering --------------------------------------------------
        $pageA = $this->read($this->hostA);
        $pageB = $this->read($this->hostB);

        self::assertSame(200, $pageA['status']);
        self::assertSame(200, $pageB['status']);

        self::assertStringContainsString('News from A', $pageA['body']);
        self::assertStringContainsString('Body belonging to tenant A.', $pageA['body']);
        self::assertStringNotContainsString('News from B', $pageA['body']);

        self::assertStringContainsString('News from B', $pageB['body']);
        self::assertStringNotContainsString('News from A', $pageB['body']);

        // ---- cache isolation ---------------------------------------------------
        // The page cache is keyed by site id, so `/news` is two independent entries.
        $cache = $this->app->container()->get(PageCacheInterface::class);

        self::assertNotNull($cache->get($this->siteId, '/news'));
        self::assertNotNull($cache->get($this->siteB, '/news'));
        self::assertNotSame(
            $cache->get($this->siteId, '/news')?->html,
            $cache->get($this->siteB, '/news')?->html
        );

        // A second read on each tenant is a HIT that still shows that tenant's content.
        $againA = $this->read($this->hostA);
        $againB = $this->read($this->hostB);

        self::assertSame('HIT', $againA['cache']);
        self::assertSame('HIT', $againB['cache']);
        self::assertStringContainsString('News from A', $againA['body']);
        self::assertStringContainsString('News from B', $againB['body']);
    }

    #[Test]
    public function seoAndTaxonomyDoNotCrossTenants(): void
    {
        $authorA = $this->makeUser('authorA');
        $authorB = (int) $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($this->siteB, [
                'username' => 'authorB',
                'email' => 'authorB@example.com',
                'password' => 'correct-horse-battery',
                'display_name' => 'Author B',
            ])->id;

        $contentA = $this->publishPage($this->siteId, 'Shared Title', 'Body A', $authorA);
        $contentB = $this->publishPage($this->siteB, 'Shared Title', 'Body B', $authorB);

        $seo = $this->app->container()->get(SeoServiceInterface::class);
        $seo->store($contentA, $this->siteId, ['meta_title' => 'SEO for A']);
        $seo->store($contentB, $this->siteB, ['meta_title' => 'SEO for B']);

        self::assertSame('SEO for A', $seo->metaFor($contentA, $this->siteId)->title);
        self::assertSame('SEO for B', $seo->metaFor($contentB, $this->siteB)->title);

        // A row from the other tenant behaves as absent, which is the documented behaviour.
        $this->expectException(\CMS\Modules\Content\Exceptions\ContentNotFoundException::class);
        $seo->metaFor($contentA, $this->siteB);
    }

    #[Test]
    public function thesameSlugProducesSeparateTermsOnEachTenant(): void
    {
        $taxonomy = $this->app->container()->get(TaxonomyServiceInterface::class);

        $termA = $taxonomy->createTerm($this->siteId, 'category', 'News');
        $termB = $taxonomy->createTerm($this->siteB, 'category', 'News');

        // Same name and slug, different rows.
        self::assertNotSame($termA->id, $termB->id);
        self::assertSame($termA->slug, $termB->slug);

        self::assertCount(1, $taxonomy->listTerms($this->siteId, 'category'));
        self::assertCount(1, $taxonomy->listTerms($this->siteB, 'category'));

        // A term from the other tenant cannot be fetched.
        $this->expectException(\CMS\Modules\Taxonomy\Exceptions\TermNotFoundException::class);
        $taxonomy->getTerm((int) $termA->id, $this->siteB);
    }

    #[Test]
    public function mediaDoesNotCrossTenants(): void
    {
        // A role is needed so the caller holds `media.read`; without it the endpoint answers
        // 403 before it can answer 404, and the test would be measuring the wrong thing.
        $authorA = $this->makeUser('authorA', 'contributor');

        $path = $this->path('uploads/a.png');
        @mkdir(dirname($path), 0775, true);
        imagepng(imagecreatetruecolor(10, 10), $path);

        $media = $this->app->container()->get(MediaServiceInterface::class)->upload(
            $this->siteId,
            $authorA,
            new UploadedFile(
                tmpPath: $path,
                originalName: 'a.png',
                size: (int) filesize($path),
                clientMime: 'image/png',
                error: UPLOAD_ERR_OK,
            ),
        );

        self::assertCount(1, $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteId));
        self::assertCount(0, $this->app->container()->get(MediaServiceInterface::class)->listForSite($this->siteB));

        // The API assertion has to come from a user of the OTHER tenant, resolving the
        // other host. Asking for site A's media while still on host A would only prove that
        // A can read its own rows.
        $roles = $this->app->container()->get(\CMS\Modules\Identity\Contracts\RoleServiceInterface::class);
        $roles->seedDefaults($this->siteB);

        $adminRoleId = null;

        foreach ($roles->listForSite($this->siteB) as $role) {
            if ($role->slug === 'administrator') {
                $adminRoleId = (int) $role->id;
            }
        }

        $adminB = (int) $this->app->container()->get(\CMS\Modules\Identity\Contracts\UserServiceInterface::class)
            ->create($this->siteB, [
                'username' => 'adminB',
                'email' => 'adminB@example.com',
                'password' => 'correct-horse-battery',
                'display_name' => 'Admin B',
            ])->id;

        self::assertNotNull($adminRoleId);
        $roles->assignRole($adminB, $adminRoleId, $this->siteB);

        // Signed in *against site B*, as a real login would: the session now verifies that its
        // user actually belongs to the site it authenticated against.
        $this->loginAs($adminB, $this->siteB);

        $crossRead = $this->app->handle(
            Request::create('GET', '/api/v1/media/' . $media->id, $this->hostB)
        );

        self::assertSame(404, $crossRead->status(), 'Media from another tenant must be a 404.');
    }

    #[Test]
    public function aUserOnOneTenantIsInvisibleOnTheOther(): void
    {
        $authorA = $this->makeUser('authorA');

        $this->loginAs($authorA);

        // The API resolves the tenant from the host, so a request for the other host cannot
        // see this user even though the same session cookie is presented.
        $response = $this->app->handle(
            Request::create('GET', '/api/v1/content', $this->hostB)
        );

        // tenant B has no content, and the authenticated user of A does not become a user of B
        self::assertSame(200, $response->status());
        self::assertSame([], json_decode($response->content(), true)['data']);

        $this->loginAs($authorA);
        $me = $this->call('GET', '/api/v1/auth/me');
        self::assertSame(200, $me->status());
        self::assertSame('authorA', $this->json($me)['data']['username']);
    }

    #[Test]
    public function themesAndPluginsAreActivatedPerTenant(): void
    {
        $themes = $this->app->container()->get(\CMS\Modules\Theme\Contracts\ThemeRegistryInterface::class);

        // `default` is the only installed theme, so activation is asserted through the
        // plugin platform, which has an explicit per-site toggle.
        $plugins = $this->app->container()->get(\CMS\Modules\Plugin\Contracts\PluginManagerInterface::class);
        $plugins->install('example-plugin');
        $plugins->activate($this->siteId, 'example-plugin');

        self::assertTrue($plugins->isActiveFor($this->siteId, 'example-plugin'));
        self::assertFalse($plugins->isActiveFor($this->siteB, 'example-plugin'));

        // Deactivating on A leaves the plugin installed for the platform.
        $plugins->deactivate($this->siteId, 'example-plugin');
        self::assertTrue($plugins->isInstalled('example-plugin'));

        self::assertSame('default', $themes->activeFor($this->siteB)->name);
    }
}
