<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Sign-in behaviour.
 *
 * The login screen is the only anonymous admin surface, so it carries the hardening: uniform
 * failures, CSRF on the POST, and the rate limiter.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\AuthController::class)]
#[CoversClass(\CMS\Modules\Admin\Http\Middleware\AdminAuthMiddleware::class)]
final class AdminAuthTest extends AdminTestCase
{
    #[Test]
    public function theLoginPageRendersWithAToken(): void
    {
        $response = $this->get('/admin/login');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sign in', $this->body($response));
        self::assertNotSame('', $this->tokenFromHtml($this->body($response)));
        self::assertStringNotContainsString('Sign out', $this->body($response));
    }

    #[Test]
    public function everyAdminPageRequiresSignIn(): void
    {
        foreach (['/admin', '/admin/content', '/admin/content/new', '/admin/content/1', '/admin/content/1/review'] as $path) {
            $response = $this->get($path);

            self::assertSame(303, $response->status(), $path . ' must redirect when anonymous.');
            self::assertStringStartsWith('/admin/login', $this->location($response), $path);
        }
    }

    #[Test]
    public function theRequestedPathSurvivesTheRedirect(): void
    {
        $response = $this->get('/admin/content/42');

        self::assertStringContainsString('next=%2Fadmin%2Fcontent%2F42', $this->location($response));
    }

    #[Test]
    public function aWrongPasswordIsRejectedWithOneUniformMessage(): void
    {
        $this->makeUser('writer', 'contributor');

        $response = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'not-the-password',
        ]);

        self::assertSame(401, $response->status());
        self::assertStringContainsString('Those details are not correct', $this->body($response));
    }

    #[Test]
    public function anUnknownAccountIsRejectedWithTheSameMessage(): void
    {
        $response = $this->post('/admin/login', [
            'identifier' => 'nobody-at-all',
            'password' => 'whatever',
        ]);

        self::assertSame(401, $response->status());
        self::assertStringContainsString('Those details are not correct', $this->body($response));
        // The form must not reveal which of the two was wrong.
        self::assertStringNotContainsString('no such', strtolower($this->body($response)));
    }

    #[Test]
    public function emptyCredentialsAreAFilledInFormError(): void
    {
        $response = $this->post('/admin/login', ['identifier' => '', 'password' => '']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Enter your username and password', $this->body($response));
    }

    #[Test]
    public function signingInLandsOnTheDashboard(): void
    {
        $this->makeUser('writer', 'contributor');

        $response = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'correct-horse-battery',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin', $this->location($response));

        $dashboard = $this->get('/admin');

        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Dashboard', $this->body($dashboard));
        self::assertStringContainsString('Sign out', $this->body($dashboard));
    }

    #[Test]
    public function signingInFollowsOnlyALocalAdminPath(): void
    {
        $this->makeUser('writer', 'contributor');

        // An accepted external `next` would make the login form an open redirect.
        $response = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'correct-horse-battery',
            'next' => 'https://evil.example/steal',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin', $this->location($response));
    }

    #[Test]
    public function aLoginPostWithoutATokenIsRejected(): void
    {
        $this->makeUser('writer', 'contributor');

        $response = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'correct-horse-battery',
        ], false);

        self::assertSame(403, $response->status());
    }

    #[Test]
    public function repeatedFailuresAreRateLimited(): void
    {
        $this->makeUser('writer', 'contributor');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/admin/login', [
                'identifier' => 'writer',
                'password' => 'wrong-' . $attempt,
            ]);

            self::assertSame(401, $response->status(), 'Attempt ' . $attempt . ' should be a credential failure.');
        }

        // The sixth attempt is refused before the password is even checked.
        $blocked = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'correct-horse-battery',
        ]);

        self::assertSame(429, $blocked->status());
        self::assertStringContainsString('Too many attempts', $this->body($blocked));
    }

    #[Test]
    public function aSuccessfulSignInClearsTheFailureCount(): void
    {
        $this->makeUser('writer', 'contributor');

        $this->post('/admin/login', ['identifier' => 'writer', 'password' => 'wrong']);
        $this->post('/admin/login', ['identifier' => 'writer', 'password' => 'wrong']);

        $ok = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'correct-horse-battery',
        ]);

        self::assertSame(303, $ok->status());

        // The bucket was cleared, so a fresh failure is still a 401 rather than a 429.
        $this->app->container()->get(\CMS\Shared\Contracts\SessionInterface::class)->destroy();

        $after = $this->post('/admin/login', ['identifier' => 'writer', 'password' => 'wrong']);

        self::assertSame(401, $after->status());
    }

    #[Test]
    public function signingOutRequiresPostAndClearsTheSession(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        self::assertSame(200, $this->get('/admin')->status());

        // A GET must not sign anyone out: an image tag on another page could do it.
        $get = $this->get('/admin/logout');
        self::assertContains($get->status(), [404, 405]);
        self::assertSame(200, $this->get('/admin')->status());

        $post = $this->post('/admin/logout');

        self::assertSame(303, $post->status());
        self::assertSame('/admin/login', $this->location($post));
        self::assertSame(303, $this->get('/admin')->status());
    }

    #[Test]
    public function aSignedInUserVisitingTheLoginPageIsSentToTheDashboard(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        $response = $this->get('/admin/login');

        self::assertSame(303, $response->status());
        self::assertSame('/admin', $this->location($response));
    }
}
