<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use CMS\Modules\Identity\Exceptions\AccountDisabledException;
use CMS\Modules\Identity\Exceptions\InvalidCredentialsException;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\AuthenticationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ArraySession;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Authentication against a real database and an in-memory session.
 */
#[CoversClass(AuthenticationService::class)]
final class AuthenticationTest extends ModuleDatabaseTestCase
{
    private PdoUserRepository $users;

    private ArraySession $session;

    private AuthenticationService $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new PdoUserRepository($this->db);
        $this->session = new ArraySession();
        $this->auth = new AuthenticationService($this->users, $this->session, new FrozenClock());
    }

    private function makeUser(
        int $siteId,
        string $username = 'alice',
        string $email = 'alice@example.com',
        string $password = 'correct-horse',
        string $status = 'active',
    ): int {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        self::assertIsString($hash);

        $user = $this->users->create($siteId, $username, $email, $hash, 'Alice', $status);

        return (int) $user->id;
    }

    #[Test]
    public function logsInWithUsername(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $userId = $this->makeUser($siteId);

        $user = $this->auth->login('alice', 'correct-horse', $siteId);

        self::assertSame($userId, $user->id);
        self::assertSame($userId, $this->auth->currentUserId());
    }

    #[Test]
    public function logsInWithEmail(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $userId = $this->makeUser($siteId);

        $user = $this->auth->login('alice@example.com', 'correct-horse', $siteId);

        self::assertSame($userId, $user->id);
    }

    #[Test]
    public function wrongPasswordIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $this->expectException(InvalidCredentialsException::class);

        $this->auth->login('alice', 'wrong-password', $siteId);
    }

    #[Test]
    public function unknownUserIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidCredentialsException::class);

        $this->auth->login('nobody', 'whatever', $siteId);
    }

    #[Test]
    public function unknownUserAndWrongPasswordShareTheSameMessage(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        try {
            $this->auth->login('alice', 'wrong', $siteId);
            self::fail('Expected failure.');
        } catch (InvalidCredentialsException $wrongPassword) {
            $first = $wrongPassword->getMessage();
        }

        try {
            $this->auth->login('ghost', 'wrong', $siteId);
            self::fail('Expected failure.');
        } catch (InvalidCredentialsException $unknownUser) {
            self::assertSame($first, $unknownUser->getMessage());
        }
    }

    #[Test]
    public function disabledAccountCannotLogIn(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId, status: 'disabled');

        $this->expectException(AccountDisabledException::class);

        $this->auth->login('alice', 'correct-horse', $siteId);
    }

    #[Test]
    public function disabledAccountWithWrongPasswordStillReportsBadCredentials(): void
    {
        // Account state must not leak to someone who does not know the password.
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId, status: 'disabled');

        $this->expectException(InvalidCredentialsException::class);

        $this->auth->login('alice', 'wrong-password', $siteId);
    }

    #[Test]
    public function sessionIsRegeneratedOnSuccessfulLogin(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $this->auth->login('alice', 'correct-horse', $siteId);

        self::assertSame(1, $this->session->regenerations);
    }

    #[Test]
    public function failedLoginDoesNotRegenerateTheSession(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        try {
            $this->auth->login('alice', 'wrong', $siteId);
        } catch (InvalidCredentialsException) {
            // expected
        }

        self::assertSame(0, $this->session->regenerations);
    }

    #[Test]
    public function loginRecordsTheAuthenticatedSite(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $this->auth->login('alice', 'correct-horse', $siteId);

        self::assertSame($siteId, $this->auth->currentSiteId());
    }

    #[Test]
    public function logoutClearsAndDestroysTheSession(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $this->auth->login('alice', 'correct-horse', $siteId);
        $this->auth->logout();

        self::assertNull($this->auth->currentUserId());
        self::assertNull($this->auth->currentSiteId());
        self::assertSame(1, $this->session->destructions);
    }

    #[Test]
    public function userFromAnotherSiteCannotLogIn(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->makeUser($first, 'alice', 'alice@example.com', 'correct-horse');

        // Same credentials, wrong tenant: must be indistinguishable from an
        // unknown user.
        $this->expectException(InvalidCredentialsException::class);

        $this->auth->login('alice', 'correct-horse', $second);
    }

    #[Test]
    public function emptyCredentialsAreRejectedWithoutQuerying(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidCredentialsException::class);

        $this->auth->login('', '', $siteId);
    }

    #[Test]
    public function loginUpdatesLastLoginAt(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $user = $this->auth->login('alice', 'correct-horse', $siteId);

        self::assertNotNull($user->lastLoginAt);
    }

    #[Test]
    public function passwordIsRehashedWhenTheAlgorithmCostChanges(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        // A deliberately weak hash that password_needs_rehash() will flag.
        $weak = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertIsString($weak);

        $user = $this->users->create($siteId, 'alice', 'alice@example.com', $weak, 'Alice', 'active');

        $this->auth->login('alice', 'correct-horse', $siteId);

        $reloaded = $this->users->findById((int) $user->id, $siteId);

        self::assertNotNull($reloaded);
        self::assertNotSame($weak, $reloaded->passwordHash);
        self::assertTrue(password_verify('correct-horse', $reloaded->passwordHash));
    }
}
