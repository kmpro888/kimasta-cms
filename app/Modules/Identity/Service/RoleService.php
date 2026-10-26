<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Service;

use CMS\Modules\Identity\Contracts\ExtraCapabilityProviderInterface;
use CMS\Modules\Identity\Contracts\RoleServiceInterface;
use CMS\Modules\Identity\Entity\Role;
use CMS\Modules\Identity\Repository\CapabilityRepositoryInterface;
use CMS\Modules\Identity\Repository\RoleRepositoryInterface;
use CMS\Modules\Identity\Repository\UserRepositoryInterface;
use CMS\Modules\Identity\Support\CapabilityCatalogue;
use InvalidArgumentException;

/**
 * Role assignment and the default role/capability seed.
 *
 * Seeding is idempotent: running it twice must not duplicate roles, duplicate
 * capability grants, or resurrect a grant an administrator deliberately
 * removed. It therefore uses INSERT IGNORE semantics through the repositories
 * and only creates what is missing.
 */
final class RoleService implements RoleServiceInterface
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly CapabilityRepositoryInterface $capabilities,
        private readonly UserRepositoryInterface $users,
        private readonly ?ExtraCapabilityProviderInterface $extraCapabilities = null,
    ) {
    }

    public function listForSite(int $siteId): array
    {
        return $this->roles->listBySite($siteId);
    }

    public function rolesForUser(int $userId, int $siteId): array
    {
        return $this->roles->listForUser($userId, $siteId);
    }

    public function assignRole(int $userId, int $roleId, int $siteId): void
    {
        // Both sides are site-scoped lookups, so a role from another tenant is
        // indistinguishable from a nonexistent one.
        if ($this->users->findById($userId, $siteId) === null) {
            throw new InvalidArgumentException('User does not exist on this site.');
        }

        if ($this->roles->findById($roleId, $siteId) === null) {
            throw new InvalidArgumentException('Role does not exist on this site.');
        }

        $this->roles->assignToUser($userId, $roleId);
    }

    public function revokeRole(int $userId, int $roleId, int $siteId): void
    {
        if ($this->roles->findById($roleId, $siteId) === null) {
            throw new InvalidArgumentException('Role does not exist on this site.');
        }

        $this->roles->revokeFromUser($userId, $roleId);
    }

    public function seedDefaults(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Cannot seed roles for an invalid site id.');
        }

        // 1. Ensure every catalogue capability exists, remembering its id.
        //
        // Capabilities contributed by other modules (plugins declare theirs) are merged
        // here. Identity never learns what a plugin is: it only asks the optional
        // provider, so the dependency points inward.
        $capabilityIds = [];
        $catalogue = CapabilityCatalogue::capabilities();
        $matrix = CapabilityCatalogue::roleMatrix();

        if ($this->extraCapabilities !== null) {
            $catalogue = array_merge($catalogue, $this->extraCapabilities->extraCapabilities());

            foreach ($this->extraCapabilities->extraRoleGrants() as $roleSlug => $keys) {
                $matrix[$roleSlug] = array_values(array_merge($matrix[$roleSlug] ?? [], $keys));
            }
        }

        foreach ($catalogue as $key => $meta) {
            $capabilityIds[$key] = $this->capabilities->ensure($key, $meta['description']);
        }

        // 2. Ensure each system role exists for this site.
        $roleIds = [];

        foreach (Role::systemSlugs() as $slug) {
            $existing = $this->roles->findBySlug($slug, $siteId);

            if ($existing === null) {
                $existing = $this->roles->create($siteId, $this->displayName($slug), $slug, true);
            }

            if ($existing->id !== null) {
                $roleIds[$slug] = $existing->id;
            }
        }

        // 3. Grant the matrix. grantToRole is INSERT IGNORE, so re-running this
        //    is a no-op and cannot duplicate rows.
        foreach ($matrix as $slug => $keys) {
            $roleId = $roleIds[$slug] ?? null;

            if ($roleId === null) {
                continue;
            }

            foreach ($keys as $key) {
                $capabilityId = $capabilityIds[$key] ?? null;

                if ($capabilityId !== null) {
                    $this->capabilities->grantToRole($roleId, $capabilityId);
                }
            }
        }
    }

    public function usersWithCapability(string $capability, int $siteId): array
    {
        return $this->roles->usersWithCapability($capability, $siteId);
    }

    /**
     * Human label for a seeded role slug.
     */
    private function displayName(string $slug): string
    {
        return match ($slug) {
            Role::ADMINISTRATOR => 'Administrator',
            Role::EDITOR => 'Editor',
            Role::REVIEWER => 'Reviewer',
            Role::AUTHOR => 'Author',
            Role::CONTRIBUTOR => 'Contributor',
            Role::SUBSCRIBER => 'Subscriber',
            default => ucfirst($slug),
        };
    }
}
