<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use CMS\Modules\Identity\Exceptions\DuplicateUserException;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\UserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Cross-tenant user isolation.
 *
 * The acceptance criteria from the task pack: the same email may exist on two
 * sites, and one site must never be able to read or mutate another site's
 * users. Every "not found" here must behave identically to a genuinely absent
 * row, so a caller cannot probe for existence.
 */
#[CoversClass(UserService::class)]
final class UserSiteIsolationTest extends ModuleDatabaseTestCase
{
    private UserService $service;

    private PdoUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new PdoUserRepository($this->db);
        $this->service = new UserService($this->users, new FrozenClock());
    }

    #[Test]
    public function theSameEmailMayExistOnTwoSites(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $a = $this->service->create($first, [
            'username' => 'alice',
            'email' => 'shared@example.com',
            'password' => 'secret-password',
        ]);

        $b = $this->service->create($second, [
            'username' => 'alice',
            'email' => 'shared@example.com',
            'password' => 'secret-password',
        ]);

        self::assertNotSame($a->id, $b->id);
        self::assertSame('shared@example.com', $a->email);
        self::assertSame('shared@example.com', $b->email);
    }

    #[Test]
    public function duplicateEmailOnTheSameSiteIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->create($siteId, [
            'username' => 'alice',
            'email' => 'shared@example.com',
            'password' => 'secret-password',
        ]);

        $this->expectException(DuplicateUserException::class);

        $this->service->create($siteId, [
            'username' => 'alice2',
            'email' => 'shared@example.com',
            'password' => 'secret-password',
        ]);
    }

    #[Test]
    public function duplicateUsernameOnTheSameSiteIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->service->create($siteId, [
            'username' => 'alice',
            'email' => 'one@example.com',
            'password' => 'secret-password',
        ]);

        $this->expectException(DuplicateUserException::class);

        $this->service->create($siteId, [
            'username' => 'alice',
            'email' => 'two@example.com',
            'password' => 'secret-password',
        ]);
    }

    #[Test]
    public function aUserIsNotVisibleFromAnotherSite(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $user = $this->service->create($first, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'secret-password',
        ]);

        self::assertNull($this->service->find((int) $user->id, $second));
        self::assertNotNull($this->service->find((int) $user->id, $first));
    }

    #[Test]
    public function listingIsScopedToTheSite(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $this->service->create($first, ['username' => 'a', 'email' => 'a@example.com', 'password' => 'pw-123456']);
        $this->service->create($second, ['username' => 'b', 'email' => 'b@example.com', 'password' => 'pw-123456']);

        self::assertCount(1, $this->service->listForSite($first));
        self::assertCount(1, $this->service->listForSite($second));
    }

    #[Test]
    public function updatingAUserFromAnotherSiteIsRejected(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $user = $this->service->create($first, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'secret-password',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->update((int) $user->id, $second, ['display_name' => 'Hijacked']);
    }

    #[Test]
    public function deletingAUserFromAnotherSiteDoesNothing(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $user = $this->service->create($first, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'secret-password',
        ]);

        self::assertFalse($this->service->delete((int) $user->id, $second));
        self::assertNotNull($this->service->find((int) $user->id, $first));
    }

    #[Test]
    public function changingPasswordFromAnotherSiteDoesNothing(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');

        $user = $this->service->create($first, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'original-password',
        ]);

        $this->service->changePassword((int) $user->id, $second, 'attacker-password');

        $reloaded = $this->users->findById((int) $user->id, $first);
        self::assertNotNull($reloaded);
        self::assertTrue(password_verify('original-password', $reloaded->passwordHash));
        self::assertFalse(password_verify('attacker-password', $reloaded->passwordHash));
    }

    #[Test]
    public function createdPasswordIsHashedNotStoredPlain(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $user = $this->service->create($siteId, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'super-secret-plaintext',
        ]);

        self::assertNotSame('super-secret-plaintext', $user->passwordHash);
        self::assertTrue(password_verify('super-secret-plaintext', $user->passwordHash));

        $raw = $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => (int) $user->id]);

        self::assertIsString($raw);
        self::assertStringNotContainsString('super-secret-plaintext', $raw);
    }

    #[Test]
    public function missingRequiredFieldsAreRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($siteId, ['username' => 'alice']);
    }

    #[Test]
    public function anUnknownStatusIsRejected(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($siteId, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'secret-password',
            'status' => 'wizard',
        ]);
    }

    #[Test]
    public function passwordCanBeChangedForTheOwningSite(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $user = $this->service->create($siteId, [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'original-password',
        ]);

        $this->service->changePassword((int) $user->id, $siteId, 'rotated-password');

        $reloaded = $this->users->findById((int) $user->id, $siteId);
        self::assertNotNull($reloaded);
        self::assertTrue(password_verify('rotated-password', $reloaded->passwordHash));
    }
}
