<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

use CMS\Modules\Identity\Entity\Role;

/**
 * Role assignment and capability mapping, scoped to a site.
 */
interface RoleServiceInterface
{
    /**
     * @return list<Role>
     */
    public function listForSite(int $siteId): array;

    /**
     * @return list<Role> roles held by a user on their site
     */
    public function rolesForUser(int $userId, int $siteId): array;

    /**
     * Assign a role to a user. Both must belong to the given site.
     */
    public function assignRole(int $userId, int $roleId, int $siteId): void;

    /**
     * Revoke a role from a user.
     */
    public function revokeRole(int $userId, int $roleId, int $siteId): void;

    /**
     * Seed the default roles and capability matrix for a site.
     */
    public function seedDefaults(int $siteId): void;

    /**
     * User ids holding a capability on this site.
     *
     * Resolved through the role assignment rather than a stored recipient list, so a role
     * change takes effect immediately. Used to find reviewers for a submission.
     *
     * @return list<int>
     */
    public function usersWithCapability(string $capability, int $siteId): array;
}