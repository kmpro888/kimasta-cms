<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;

/**
 * Boots the real application for integration tests.
 *
 * Uses the actual composition root — the same `app/bootstrap.php` that
 * `public/index.php` loads — so a missing provider binding fails here rather than in
 * production. A hand-wired container would hide exactly that class of bug.
 *
 * The session is swapped for a controllable one so a test can act as a logged-in
 * user. `forget()` is deliberately not used: it removes the container binding as well
 * as the resolved instance, which would make the identity services unresolvable.
 */
abstract class AppTestCase extends ModuleDatabaseTestCase
{
    protected \CMS\Core\Bootstrap\Application $app;

    protected ArraySession $session;

    protected int $siteId;

    protected string $host = 'example.com';

    /** @var array<string, int> */
    protected array $roleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = $this->createSiteRow('main', $this->host);

        $app = require CMS_TEST_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
        $app->boot();
        $this->app = $app;

        $this->session = new ArraySession();
        $this->app->container()->instance(\CMS\Shared\Contracts\SessionInterface::class, $this->session);

        // Real defaults, so capabilities come from the shipped role definitions.
        $roleService = $this->app->container()->get(RoleServiceInterface::class);
        $roleService->seedDefaults($this->siteId);

        foreach ($roleService->listForSite($this->siteId) as $role) {
            $this->roleIds[$role->slug] = (int) $role->id;
        }

        // The domain map must know this host for tenant resolution.
        $this->app->container()->get(\CMS\Modules\Site\Contracts\SiteServiceInterface::class)
            ->regenerateDomainMap();

        // The rate limiter keeps its counters in the project's filesystem cache, which is
        // shared across the whole run. Without this, the negative login tests (wrong
        // password, unknown account) would accumulate across test methods and start
        // answering 429 halfway through the suite -- a failure that has nothing to do with
        // the code under test. Each test gets its own bucket.
        $this->app->container()->instance(
            \CMS\Core\Security\RateLimiter::class,
            new \CMS\Core\Security\RateLimiter($this->path('rate-limits'))
        );
    }

    protected function makeUser(string $username, ?string $roleSlug = null): int
    {
        $users = $this->app->container()->get(UserServiceInterface::class);

        $user = $users->create($this->siteId, [
            'username' => $username,
            'email' => $username . '@example.com',
            'password' => 'correct-horse-battery',
            'display_name' => ucfirst($username),
        ]);

        if ($roleSlug !== null && isset($this->roleIds[$roleSlug])) {
            $this->app->container()->get(RoleServiceInterface::class)
                ->assignRole((int) $user->id, $this->roleIds[$roleSlug], $this->siteId);
        }

        return (int) $user->id;
    }

    protected function loginAs(int $userId, ?int $siteId = null): void
    {
        // Exactly what AuthenticationService stores on a successful login. The session
        // object is shared by reference, so the identity services see this without
        // being re-resolved.
        //
        // The fingerprint is deliberately not written here: a real login writes it, and a session
        // without one is adopted once by `currentUserId()`. Switching users mid-test therefore
        // behaves like a fresh login because the previous fingerprint is cleared first.
        $this->session->forget('auth.fingerprint');
        $this->session->put('auth.user_id', $userId);
        $this->session->put('auth.site_id', $siteId ?? $this->siteId);
    }

    protected function logout(): void
    {
        $this->session->forget('auth.user_id');
        $this->session->forget('auth.site_id');
    }

    protected function publishArticle(string $title = 'Seed Article', string $type = 'post'): int
    {
        return (int) $this->app->container()->get(ContentServiceInterface::class)->create($this->siteId, [
            'type' => $type,
            'title' => $title,
            'author_id' => 1,
            'status' => 'published',
            'excerpt' => 'A summary.',
            'content_json' => [['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']]],
        ])->id;
    }
}
