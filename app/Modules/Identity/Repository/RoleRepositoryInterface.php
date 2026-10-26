<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Modules\Identity\Entity\Role;

/**
 * Role persistence, always site scoped.
 */
interface RoleRepositoryInterface
{
    public function findById(int $id, int $siteId): ?Role;

    public function findBySlug(string $slug, int $siteId): ?Role;

    /** @return list<Role> */
    public function listBySite(int $siteId): array;

    /** @return list<Role> */
    public function listForUser(int $userId, int $siteId): array;

    public function create(int $siteId, string $name, string $slug, bool $isSystem): Role;

    public function assignToUser(int $userId, int $roleId): void;

    public function revokeFromUser(int $userId, int $roleId): void;

    /**
     * Resolved capability keys granted to a user through their roles.
     * Only roles belonging to the given site contribute.
     *
     * @return list<string>
     */
    public function capabilitiesForUser(int $userId, int $siteId): array;

    /** @return list<string> */
    public function capabilityKeysForRole(int $roleId, int $siteId): array;

    /**
     * User ids holding a capability on a site, resolved through their roles.
     *
     * @return list<int>
     */
    public function usersWithCapability(string $capability, int $siteId): array;
}