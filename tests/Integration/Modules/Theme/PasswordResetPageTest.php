<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Theme;

use CMS\Core\Http\Request;

use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * The public page an administrator's reset link lands on.
 *
 * The token and the service existed; this is the other half of the chain. Without it the link an
 * administrator issues goes to a 404 from the theme.
 */
#[CoversClass(\CMS\Modules\Theme\Http\PasswordResetController::class)]
final class PasswordResetPageTest extends AppTestCase
{
    private function user(string $username = 'forgetful'): int
    {
        return $this->makeUser($username, 'contributor');
    }

    private function token(string $email): string
    {
        $token = $this->app->container()->get(PasswordResetServiceInterface::class)
            ->issue($email, $this->siteId);

        self::assertNotNull($token, 'A reset token must be issued.');

        return (string) $token;
    }

    private function get(string $path, array $query = []): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host, $query));
    }

    private function post(array $fields): \CMS\Core\Http\Response
    {
        return $this->app->handle(Request::create('POST', '/reset-password', $this->host, [], $fields));
    }

    #[Test]
    public function theFormIsServedForAValidToken(): void
    {
        $id = $this->user();
        $user = $this->app->container()->get(UserServiceInterface::class)->find($id, $this->siteId);
        $token = $this->token((string) $user->email);

        $response = $this->get('/reset-password', ['token' => $token]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Choose a new password', $response->content());
        self::assertStringContainsString('name="password_confirmation"', $response->content());
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    #[Test]
    public function aMissingTokenIsExplainedRatherThanA404(): void
    {
        $response = $this->get('/reset-password');

        self::assertSame(422, $response->status());
        self::assertStringContainsString('missing its token', $response->content());
    }

    #[Test]
    public function aValidTokenSetsThePasswordAndTheNewOneSignsIn(): void
    {
        $id = $this->user();
        $user = $this->app->container()->get(UserServiceInterface::class)->find($id, $this->siteId);
        $token = $this->token((string) $user->email);

        $response = $this->post([
            'token' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Password changed', $response->content());

        // The whole point: the new password is what the account now verifies against.
        $updated = $this->app->container()->get(UserServiceInterface::class)->find($id, $this->siteId);

        self::assertNotNull($updated);
        self::assertTrue(password_verify('a-brand-new-password', $updated->passwordHash));
        self::assertFalse(password_verify('correct-horse-battery', $updated->passwordHash));
    }

    #[Test]
    public function theTokenCannotBeUsedTwice(): void
    {
        $id = $this->user();
        $user = $this->app->container()->get(UserServiceInterface::class)->find($id, $this->siteId);
        $token = $this->token((string) $user->email);

        $this->post(['token' => $token, 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password']);

        $second = $this->post([
            'token' => $token,
            'password' => 'another-password-here',
            'password_confirmation' => 'another-password-here',
        ]);

        self::assertSame(422, $second->status());
        self::assertStringContainsString('no longer works', $second->content());
    }

    #[Test]
    public function mismatchedPasswordsAreRefusedWithoutConsumingTheToken(): void
    {
        $id = $this->user();
        $user = $this->app->container()->get(UserServiceInterface::class)->find($id, $this->siteId);
        $token = $this->token((string) $user->email);

        $response = $this->post([
            'token' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'something-else',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('do not match', $response->content());

        // The token survives a rejected attempt: the visitor may simply retype.
        $retry = $this->post([
            'token' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        self::assertSame(200, $retry->status());
    }

    #[Test]
    public function aShortPasswordIsRefusedByThePolicy(): void
    {
        $id = $this->user();
        $user = $this->app->container()->get(UserServiceInterface::class)->find($id, $this->siteId);
        $token = $this->token((string) $user->email);

        $response = $this->post([
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('at least 8 characters', $response->content());
    }

    #[Test]
    public function aMadeUpTokenIsRefused(): void
    {
        $response = $this->post([
            'token' => 'not-a-real-token',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('no longer works', $response->content());
    }
}
