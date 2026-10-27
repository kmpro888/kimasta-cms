<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Identity;

use CMS\Modules\Identity\Exceptions\InvalidCredentialsException;
use CMS\Modules\Identity\Repository\PdoPasswordResetRepository;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\AuthenticationService;
use CMS\Modules\Identity\Service\PasswordResetService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ArraySession;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Credential-handling security properties.
 *
 * These assert the things that must never regress: no plaintext secrets at
 * rest, no enumeration oracles, and no session escalation.
 */
final class AuthSecurityTest extends ModuleDatabaseTestCase
{
    private PdoUserRepository $users;

    private AuthenticationService $auth;

    private ArraySession $session;

    private FrozenClock $clock;

    private PasswordResetService $resets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new PdoUserRepository($this->db);
        $this->session = new ArraySession();
        $this->clock = new FrozenClock();
        $this->auth = new AuthenticationService($this->users, $this->session, $this->clock);
        $this->resets = new PasswordResetService(
            $this->users,
            new PdoPasswordResetRepository($this->db),
            $this->clock,
            60,
        );
    }

    private function makeUser(int $siteId, string $username = 'alice', string $password = 'correct-horse'): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        self::assertIsString($hash);

        $user = $this->users->create($siteId, $username, $username . '@example.com', $hash, 'Alice', 'active');

        return (int) $user->id;
    }

    #[Test]
    public function thePasswordColumnNeverContainsPlaintext(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId, 'alice', 'plaintext-canary-value');

        $stored = $this->db->scalar('SELECT password_hash FROM users LIMIT 1');

        self::assertIsString($stored);
        self::assertStringNotContainsString('plaintext-canary-value', $stored);
        self::assertNotSame('plaintext-canary-value', $stored);
    }

    #[Test]
    public function thePasswordHashIsARealAlgorithmHash(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $stored = $this->db->scalar('SELECT password_hash FROM users LIMIT 1');

        self::assertIsString($stored);

        $info = password_get_info($stored);

        // algo must be a real hash, not "unknown" (which would be 0).
        self::assertNotSame(0, $info['algo']);
    }

    #[Test]
    public function resetTokensAreStoredOnlyAsHashes(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = (string) $this->resets->issue('alice', $siteId);

        $rows = $this->db->select('SELECT token_hash FROM password_resets');

        self::assertCount(1, $rows);

        $stored = (string) $rows[0]['token_hash'];

        self::assertNotSame($token, $stored);
        self::assertSame(64, strlen($stored), 'Expected a SHA-256 hex digest.');
    }

    #[Test]
    public function thePlaintextTokenIsNotRecoverableFromTheDatabase(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = (string) $this->resets->issue('alice', $siteId);

        $dump = json_encode($this->db->select('SELECT * FROM password_resets'));
        self::assertIsString($dump);

        self::assertStringNotContainsString($token, $dump);
    }

    #[Test]
    public function loginFailureDoesNotRevealWhichFieldWasWrong(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $messages = [];

        foreach ([['alice', 'wrong'], ['ghost', 'wrong'], ['alice', '']] as [$identifier, $password]) {
            try {
                $this->auth->login($identifier, $password, $siteId);
                self::fail('Expected failure.');
            } catch (InvalidCredentialsException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages));
    }

    #[Test]
    public function failedLoginLeavesTheSessionUnauthenticated(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        try {
            $this->auth->login('alice', 'wrong-password', $siteId);
        } catch (InvalidCredentialsException) {
            // expected
        }

        self::assertNull($this->auth->currentUserId());
        self::assertSame(0, $this->session->regenerations);
    }

    #[Test]
    public function theSessionIdRotatesOnLogin(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $this->auth->login('alice', 'correct-horse', $siteId);

        // Rotation happens before the identity is written. The fingerprint is part of that write:
        // it is what lets a password change end the session later.
        self::assertSame(
            ['regenerate', 'put:auth.user_id', 'put:auth.site_id', 'put:auth.fingerprint'],
            $this->session->log
        );
    }

    #[Test]
    public function logoutDestroysTheSessionState(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $this->auth->login('alice', 'correct-horse', $siteId);
        $this->auth->logout();

        self::assertSame([], $this->session->all());
        self::assertSame(1, $this->session->destructions);
    }

    #[Test]
    public function anExpiredTokenCannotBeUsedEvenWithTheCorrectPassword(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $userId = $this->makeUser($siteId);

        $token = (string) $this->resets->issue('alice', $siteId);
        $this->clock->advanceMinutes(120);

        try {
            $this->resets->consume($token, 'attacker-password', $siteId);
            self::fail('Expired token was accepted.');
        } catch (\CMS\Modules\Identity\Exceptions\ExpiredResetTokenException) {
            // expected
        }

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);
        self::assertFalse(password_verify('attacker-password', $user->passwordHash));
    }

    #[Test]
    public function aResetTokenFromOneSiteCannotResetAnotherSitesUser(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $victim = $this->makeUser($first, 'alice', 'victim-password');
        $this->makeUser($second, 'bob', 'bob-password');

        $token = (string) $this->resets->issue('alice', $first);

        try {
            $this->resets->consume($token, 'attacker-password', $second);
            self::fail('Cross-site reset was accepted.');
        } catch (\CMS\Modules\Identity\Exceptions\InvalidResetTokenException) {
            // expected
        }

        $user = $this->users->findById($victim, $first);
        self::assertNotNull($user);
        self::assertTrue(password_verify('victim-password', $user->passwordHash));
    }

    #[Test]
    public function issuingForAnUnknownUserDoesNotThrowAndSoCannotBeDistinguished(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $known = $this->resets->issue('alice', $siteId);
        $unknown = $this->resets->issue('nobody', $siteId);

        // Both calls succeed; only the return value differs, and the controller
        // is expected to present one identical message.
        self::assertIsString($known);
        self::assertNull($unknown);
    }

    #[Test]
    public function aDisabledAccountCannotObtainAResetToken(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $hash = password_hash('x', PASSWORD_DEFAULT);
        self::assertIsString($hash);
        $this->users->create($siteId, 'off', 'off@example.com', $hash, 'Off', 'disabled');

        self::assertNull($this->resets->issue('off', $siteId));
    }
}
