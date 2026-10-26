<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Shared\Contracts\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Changing your own password.
 *
 * The screen asks for the current password even though the session is authenticated: a session
 * left open on a shared machine must not be enough to lock the owner out.
 */
#[CoversClass(\CMS\Modules\Admin\Http\Controller\AuthController::class)]
final class AdminPasswordTest extends AdminTestCase
{
    private const CURRENT = 'correct-horse-battery';

    #[Test]
    public function theScreenRequiresSignIn(): void
    {
        self::assertSame(303, $this->get('/admin/password')->status());
    }

    #[Test]
    public function theScreenRendersInsideTheAdminShell(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        $response = $this->get('/admin/password');
        $html = $this->body($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Change password', $html);
        self::assertStringContainsString('name="current_password"', $html);
        self::assertStringContainsString('minlength="8"', $html);
        self::assertStringContainsString('Sign out', $html, 'The shell, not a bare page.');
    }

    #[Test]
    public function aWrongCurrentPasswordIsRefusedAndChangesNothing(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        $response = $this->post('/admin/password', [
            'current_password' => 'not-my-password',
            'new_password' => 'a-brand-new-secret',
            'confirm_password' => 'a-brand-new-secret',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('current password is not correct', $this->body($response));

        // The old password still works, so nothing was written.
        self::assertTrue($this->passwordMatches($userId, self::CURRENT));
        self::assertFalse($this->passwordMatches($userId, 'a-brand-new-secret'));
    }

    #[Test]
    public function aMismatchedConfirmationIsRefused(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        $response = $this->post('/admin/password', [
            'current_password' => self::CURRENT,
            'new_password' => 'a-brand-new-secret',
            'confirm_password' => 'a-brand-new-secreto',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('do not match', $this->body($response));
        self::assertTrue($this->passwordMatches($userId, self::CURRENT));
    }

    #[Test]
    public function aShortNewPasswordIsRefused(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        $response = $this->post('/admin/password', [
            'current_password' => self::CURRENT,
            'new_password' => 'short',
            'confirm_password' => 'short',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('at least 8 characters', $this->body($response));
        self::assertTrue($this->passwordMatches($userId, self::CURRENT));
    }

    #[Test]
    public function emptyFieldsAreRefused(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        $response = $this->post('/admin/password', [
            'current_password' => '',
            'new_password' => '',
            'confirm_password' => '',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Fill in every field', $this->body($response));
    }

    #[Test]
    public function aValidChangeWorksAndTheNewPasswordIsTheOneThatSignsIn(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        $response = $this->post('/admin/password', [
            'current_password' => self::CURRENT,
            'new_password' => 'a-brand-new-secret',
            'confirm_password' => 'a-brand-new-secret',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin', $this->location($response));

        self::assertTrue($this->passwordMatches($userId, 'a-brand-new-secret'));
        self::assertFalse($this->passwordMatches($userId, self::CURRENT));

        // Prove it through the real sign-in form, not only against the hash.
        $this->app->container()->get(SessionInterface::class)->destroy();

        $signedIn = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => 'a-brand-new-secret',
        ]);

        self::assertSame(303, $signedIn->status());
        self::assertSame('/admin', $this->location($signedIn));
    }

    #[Test]
    public function theOldPasswordNoLongerSignsIn(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        $this->post('/admin/password', [
            'current_password' => self::CURRENT,
            'new_password' => 'a-brand-new-secret',
            'confirm_password' => 'a-brand-new-secret',
        ]);

        $this->app->container()->get(SessionInterface::class)->destroy();

        $response = $this->post('/admin/login', [
            'identifier' => 'writer',
            'password' => self::CURRENT,
        ]);

        self::assertSame(401, $response->status());
    }

    #[Test]
    public function repeatedWrongCurrentPasswordsAreRateLimited(): void
    {
        $this->loginAs($this->makeUser('writer', 'contributor'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/admin/password', [
                'current_password' => 'wrong-' . $attempt,
                'new_password' => 'a-brand-new-secret',
                'confirm_password' => 'a-brand-new-secret',
            ]);

            self::assertSame(422, $response->status(), 'Attempt ' . $attempt . ' is a credential failure.');
        }

        $blocked = $this->post('/admin/password', [
            'current_password' => self::CURRENT,
            'new_password' => 'a-brand-new-secret',
            'confirm_password' => 'a-brand-new-secret',
        ]);

        self::assertSame(429, $blocked->status());
        self::assertStringContainsString('Too many attempts', $this->body($blocked));
    }

    #[Test]
    public function aPasswordChangeRequiresACsrfToken(): void
    {
        $userId = $this->makeUser('writer', 'contributor');
        $this->loginAs($userId);

        $response = $this->post('/admin/password', [
            'current_password' => self::CURRENT,
            'new_password' => 'a-brand-new-secret',
            'confirm_password' => 'a-brand-new-secret',
        ], false);

        self::assertSame(403, $response->status());
        self::assertTrue($this->passwordMatches($userId, self::CURRENT));
    }

    /**
     * Verify by attempting the real sign-in, which is what the hash is for.
     */
    private function passwordMatches(int $userId, string $candidate): bool
    {
        $user = $this->app->container()->get(UserServiceInterface::class)->find($userId, $this->siteId);

        if ($user === null) {
            return false;
        }

        return password_verify($candidate, $user->passwordHash);
    }
}
