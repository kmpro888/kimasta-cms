<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use CMS\Modules\Identity\Auth\ActorProvider;
use CMS\Modules\Identity\Entity\Role;
use CMS\Modules\Identity\Repository\PdoCapabilityRepository;
use CMS\Modules\Identity\Repository\PdoRoleRepository;
use CMS\Modules\Identity\Repository\PdoUserRepository;
use CMS\Modules\Identity\Service\AuthorizationService;
use CMS\Modules\Identity\Service\RoleService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ModuleDatabaseTestCase;

/**
 * Capability authorisation driven by the seeded role matrix.
 *
 * These tests prove the two acceptance criteria that matter most: a contributor
 * cannot publish, and capabilities follow the data rather than role names.
 */
#[CoversClass(AuthorizationService::class)]
#[CoversClass(RoleService::class)]
#[CoversClass(ActorProvider::class)]
final class AuthorizationTest extends ModuleDatabaseTestCase
{
    private PdoUserRepository $users;

    private PdoRoleRepository $roles;

    private RoleService $roleService;

    private AuthorizationService $authorization;

    private ActorProvider $actors;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new PdoUserRepository($this->db);
        $this->roles = new PdoRoleRepository($this->db);
        $capabilities = new PdoCapabilityRepository($this->db);

        $this->roleService = new RoleService($this->roles, $capabilities, $this->users);
        $this->authorization = new AuthorizationService($this->roles);
        $this->actors = new ActorProvider($this->users, $this->roles);
    }

    private function makeUser(int $siteId, string $username = 'alice'): int
    {
        $hash = password_hash('secret-password', PASSWORD_DEFAULT);
        self::assertIsString($hash);

        $user = $this->users->create($siteId, $username, $username . '@example.com', $hash, 'Alice', 'active');

        return (int) $user->id;
    }

    #[Test]
    public function seedingCreatesEverySystemRole(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->roleService->seedDefaults($siteId);

        $slugs = array_map(static fn (Role $role): string => $role->slug, $this->roles->listBySite($siteId));

        foreach (Role::systemSlugs() as $expected) {
            self::assertContains($expected, $slugs);
        }
    }

    #[Test]
    public function seedingIsIdempotent(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $this->roleService->seedDefaults($siteId);
        $firstCount = count($this->roles->listBySite($siteId));

        $this->roleService->seedDefaults($siteId);
        $secondCount = count($this->roles->listBySite($siteId));

        self::assertSame($firstCount, $secondCount);
    }

    #[Test]
    public function contributorCannotPublish(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);
        $role = $this->roles->findBySlug(Role::CONTRIBUTOR, $siteId);
        self::assertNotNull($role);

        $this->roleService->assignRole($userId, (int) $role->id, $siteId);

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);

        self::assertTrue($this->authorization->can($user, 'content.create'));
        self::assertFalse($this->authorization->can($user, 'content.publish'));
    }

    #[Test]
    public function editorCanPublish(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);
        $role = $this->roles->findBySlug(Role::EDITOR, $siteId);
        self::assertNotNull($role);
        $this->roleService->assignRole($userId, (int) $role->id, $siteId);

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);

        self::assertTrue($this->authorization->can($user, 'content.publish'));
    }

    #[Test]
    public function capabilitiesAccumulateAcrossRoles(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);

        $contributor = $this->roles->findBySlug(Role::CONTRIBUTOR, $siteId);
        $reviewer = $this->roles->findBySlug(Role::REVIEWER, $siteId);
        self::assertNotNull($contributor);
        self::assertNotNull($reviewer);

        $this->roleService->assignRole($userId, (int) $contributor->id, $siteId);
        $this->roleService->assignRole($userId, (int) $reviewer->id, $siteId);

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);

        self::assertTrue($this->authorization->can($user, 'content.create'));
        self::assertTrue($this->authorization->can($user, 'workflow.review'));
        self::assertFalse($this->authorization->can($user, 'content.publish'));
    }

    #[Test]
    public function revokingARoleRemovesItsCapabilities(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);
        $role = $this->roles->findBySlug(Role::EDITOR, $siteId);
        self::assertNotNull($role);

        $this->roleService->assignRole($userId, (int) $role->id, $siteId);
        $this->roleService->revokeRole($userId, (int) $role->id, $siteId);

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);

        self::assertFalse($this->authorization->can($user, 'content.publish'));
    }

    #[Test]
    public function assigningTheSameRoleTwiceIsHarmless(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);
        $role = $this->roles->findBySlug(Role::CONTRIBUTOR, $siteId);
        self::assertNotNull($role);

        $this->roleService->assignRole($userId, (int) $role->id, $siteId);
        $this->roleService->assignRole($userId, (int) $role->id, $siteId);

        self::assertCount(1, $this->roleService->rolesForUser($userId, $siteId));
    }

    #[Test]
    public function capabilityFollowsTheDataNotTheRoleName(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);

        // A custom role named nothing like a system role, granted explicitly.
        $custom = $this->roles->create($siteId, 'Publisher', 'publisher', false);
        $capabilities = new PdoCapabilityRepository($this->db);
        $publishId = $capabilities->ensure('content.publish', 'Publish content.');
        $capabilities->grantToRole((int) $custom->id, $publishId);

        $this->roleService->assignRole($userId, (int) $custom->id, $siteId);

        $user = $this->users->findById($userId, $siteId);
        self::assertNotNull($user);

        self::assertTrue($this->authorization->can($user, 'content.publish'));
    }

    #[Test]
    public function anonymousActorHoldsNoCapabilities(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $actor = $this->actors->actorForSite($siteId);

        self::assertFalse($actor->authenticated());
        self::assertSame([], $this->authorization->capabilitiesForAnonymous($siteId));
        self::assertFalse($actor->can('content.read'));
    }

    #[Test]
    public function actorProviderResolvesCapabilitiesForAUser(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $userId = $this->makeUser($siteId);
        $role = $this->roles->findBySlug(Role::EDITOR, $siteId);
        self::assertNotNull($role);
        $this->roleService->assignRole($userId, (int) $role->id, $siteId);

        $actor = $this->actors->actorForUser($userId, $siteId);

        self::assertTrue($actor->authenticated());
        self::assertSame($userId, $actor->id());
        self::assertTrue($actor->can('content.publish'));
    }

    #[Test]
    public function actorProviderReturnsAnonymousForAUserFromAnotherSite(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');
        $this->roleService->seedDefaults($first);
        $this->roleService->seedDefaults($second);

        $userId = $this->makeUser($first);
        $role = $this->roles->findBySlug(Role::ADMINISTRATOR, $first);
        self::assertNotNull($role);
        $this->roleService->assignRole($userId, (int) $role->id, $first);

        // Resolving the same id inside the other tenant must yield anonymous.
        $actor = $this->actors->actorForUser($userId, $second);

        self::assertFalse($actor->authenticated());
        self::assertFalse($actor->can('content.publish'));
    }

    #[Test]
    public function disabledUserResolvesToAnonymousActor(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $hash = password_hash('x', PASSWORD_DEFAULT);
        self::assertIsString($hash);
        $user = $this->users->create($siteId, 'off', 'off@example.com', $hash, 'Off', 'disabled');

        $role = $this->roles->findBySlug(Role::ADMINISTRATOR, $siteId);
        self::assertNotNull($role);
        $this->roleService->assignRole((int) $user->id, (int) $role->id, $siteId);

        $actor = $this->actors->actorForUser((int) $user->id, $siteId);

        self::assertFalse($actor->authenticated());
    }

    #[Test]
    public function disabledUserCannotPassCanDirectly(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');
        $this->roleService->seedDefaults($siteId);

        $hash = password_hash('x', PASSWORD_DEFAULT);
        self::assertIsString($hash);
        $user = $this->users->create($siteId, 'off', 'off@example.com', $hash, 'Off', 'disabled');

        self::assertFalse($this->authorization->can($user, 'content.read'));
    }

    #[Test]
    public function rolesCannotBeAssignedAcrossSites(): void
    {
        $first = $this->createSiteRow('one', 'one.example');
        $second = $this->createSiteRow('two', 'two.example');
        $this->roleService->seedDefaults($first);

        $userId = $this->makeUser($second, 'bob');
        $foreignRole = $this->roles->findBySlug(Role::EDITOR, $first);
        self::assertNotNull($foreignRole);

        $this->expectException(\InvalidArgumentException::class);

        $this->roleService->assignRole($userId, (int) $foreignRole->id, $second);
    }
}
