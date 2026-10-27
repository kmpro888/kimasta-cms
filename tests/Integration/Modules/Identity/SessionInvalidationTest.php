<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * A password change ends every session that signed in with the old one.
 *
 * Before this, changing a password locked the front door and left the other keys in the lock: a
 * session opened elsewhere — on a stolen laptop, in another browser — stayed authenticated forever,
 * which is the exact scenario a password change is supposed to end.
 */
#[CoversClass(\CMS\Modules\Identity\Service\AuthenticationService::class)]
final class SessionInvalidationTest extends AppTestCase
{
    private function auth(): AuthenticationServiceInterface
    {
        return $this->app->container()->get(AuthenticationServiceInterface::class);
    }

    private function users(): UserServiceInterface
    {
        return $this->app->container()->get(UserServiceInterface::class);
    }

    #[Test]
    public function changingThePasswordEndsTheSessionThatUsedTheOldOne(): void
    {
        $id = $this->makeUser('reader', 'contributor');

        $this->auth()->login('reader', 'correct-horse-battery', $this->siteId);
        self::assertSame($id, $this->auth()->currentUserId(), 'Signed in.');

        // The password changes somewhere else — the profile screen, an admin reset link.
        $this->users()->changePassword($id, $this->siteId, 'a-different-password');

        self::assertNull($this->auth()->currentUserId(), 'The old session must no longer be authenticated.');
    }

    #[Test]
    public function signingInAgainWithTheNewPasswordWorks(): void
    {
        $id = $this->makeUser('reader', 'contributor');

        $this->auth()->login('reader', 'correct-horse-battery', $this->siteId);
        $this->users()->changePassword($id, $this->siteId, 'a-different-password');

        $this->auth()->login('reader', 'a-different-password', $this->siteId);

        self::assertSame($id, $this->auth()->currentUserId());
    }

    #[Test]
    public function aResetLinkEndsTheSessionsToo(): void
    {
        $id = $this->makeUser('reader', 'contributor');
        $user = $this->users()->find($id, $this->siteId);
        self::assertNotNull($user);

        $this->auth()->login('reader', 'correct-horse-battery', $this->siteId);

        $token = $this->app->container()->get(PasswordResetServiceInterface::class)
            ->issue((string) $user->email, $this->siteId);
        self::assertNotNull($token);

        $this->app->container()->get(PasswordResetServiceInterface::class)
            ->consume((string) $token, 'from-the-reset-page', $this->siteId);

        self::assertNull(
            $this->auth()->currentUserId(),
            'Redeeming a reset link must end the sessions that predate it.'
        );
    }

    #[Test]
    public function anUnchangedPasswordLeavesTheSessionAlone(): void
    {
        $id = $this->makeUser('reader', 'contributor');

        $this->auth()->login('reader', 'correct-horse-battery', $this->siteId);

        // Somebody else's password changes; this session must survive.
        $other = $this->makeUser('other', 'contributor');
        $this->users()->changePassword($other, $this->siteId, 'their-new-password');

        self::assertSame($id, $this->auth()->currentUserId());
    }
}
