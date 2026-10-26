<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use CMS\Modules\Identity\Exceptions\ExpiredResetTokenException;
use CMS\Modules\Identity\Exceptions\InvalidResetTokenException;
use CMS\Modules\Identity\Repository\PdoPasswordResetRepository;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\PasswordResetService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Password reset issue/consume behaviour.
 */
#[CoversClass(PasswordResetService::class)]
final class PasswordResetTest extends ModuleDatabaseTestCase
{
    private PdoUserRepository $users;

    private PdoPasswordResetRepository $resets;

    private FrozenClock $clock;

    private PasswordResetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new PdoUserRepository($this->db);
        $this->resets = new PdoPasswordResetRepository($this->db);
        $this->clock = new FrozenClock();
        $this->service = new PasswordResetService($this->users, $this->resets, $this->clock, 60);
    }

    private function makeUser(int $siteId, string $username = 'alice'): int
    {
        $hash = password_hash('old-password', PASSWORD_DEFAULT);
        self::assertIsString($hash);

        $user = $this->users->create($siteId, $username, $username . '@example.com', $hash, 'Alice', 'active');

        return (int) $user->id;
    }

    #[Test]
    public function issueReturnsAPlaintextToken(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = $this->service->issue('alice', $siteId);

        self::assertIsString($token);
        self::assertSame(64, strlen($token));
    }

    #[Test]
    public function issueReturnsNullForAnUnknownUser(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        self::assertNull($this->service->issue('ghost', $siteId));
    }

    #[Test]
    public function issueReturnsNullForADisabledUser(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $hash = password_hash('x', PASSWORD_DEFAULT);
        self::assertIsString($hash);
        $this->users->create($siteId, 'off', 'off@example.com', $hash, 'Off', 'disabled');

        self::assertNull($this->service->issue('off', $siteId));
    }

    #[Test]
    public function onlyTheHashIsStored(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = $this->service->issue('alice', $siteId);
        self::assertIsString($token);

        $stored = $this->db->scalar('SELECT token_hash FROM password_resets LIMIT 1');

        self::assertIsString($stored);
        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
    }

    #[Test]
    public function consumeChangesThePassword(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $userId = $this->makeUser($siteId);

        $token = (string) $this->service->issue('alice', $siteId);
        $returnedUserId = $this->service->consume($token, 'brand-new-password', $siteId);

        self::assertSame($userId, $returnedUserId);

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);
        self::assertTrue(password_verify('brand-new-password', $user->passwordHash));
        self::assertFalse(password_verify('old-password', $user->passwordHash));
    }

    #[Test]
    public function tokenIsSingleUse(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = (string) $this->service->issue('alice', $siteId);
        $this->service->consume($token, 'first-new-password', $siteId);

        $this->expectException(InvalidResetTokenException::class);

        $this->service->consume($token, 'second-new-password', $siteId);
    }

    #[Test]
    public function expiredTokenIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = (string) $this->service->issue('alice', $siteId);

        $this->clock->advanceMinutes(61);

        $this->expectException(ExpiredResetTokenException::class);

        $this->service->consume($token, 'too-late-password', $siteId);
    }

    #[Test]
    public function expiredTokenIsBurnedAndCannotBeReplayed(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $token = (string) $this->service->issue('alice', $siteId);
        $this->clock->advanceMinutes(61);

        try {
            $this->service->consume($token, 'too-late-password', $siteId);
        } catch (ExpiredResetTokenException) {
            // expected
        }

        // Second attempt is now an invalid (consumed) token, not an expired one.
        $this->expectException(InvalidResetTokenException::class);

        $this->service->consume($token, 'again-password', $siteId);
    }

    #[Test]
    public function unknownTokenIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidResetTokenException::class);

        $this->service->consume(str_repeat('a', 64), 'whatever-password', $siteId);
    }

    #[Test]
    public function emptyTokenIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(InvalidResetTokenException::class);

        $this->service->consume('', 'whatever-password', $siteId);
    }

    #[Test]
    public function tokenIssuedForOneSiteIsInvalidOnAnother(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');
        $this->makeUser($first);

        $token = (string) $this->service->issue('alice', $first);

        $this->expectException(InvalidResetTokenException::class);

        $this->service->consume($token, 'cross-site-password', $second);
    }

    #[Test]
    public function consumingOneTokenInvalidatesSiblingTokens(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        $tokenA = (string) $this->service->issue('alice', $siteId);
        $tokenB = (string) $this->service->issue('alice', $siteId);

        $this->service->consume($tokenA, 'first-new-password', $siteId);

        $this->expectException(InvalidResetTokenException::class);

        $this->service->consume($tokenB, 'second-new-password', $siteId);
    }

    #[Test]
    public function issueCanBeCalledByUsernameOrEmail(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->makeUser($siteId);

        self::assertNotNull($this->service->issue('alice', $siteId));
        self::assertNotNull($this->service->issue('alice@example.com', $siteId));
    }

    #[Test]
    public function blankIdentifierIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        self::assertNull($this->service->issue('   ', $siteId));
    }
}
