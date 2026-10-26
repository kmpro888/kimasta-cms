<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Modules\Identity\Entity\Capability;

/**
 * Global capability registry persistence.
 */
interface CapabilityRepositoryInterface
{
    public function findByKey(string $key): ?Capability;

    /** @return list<Capability> */
    public function listAll(): array;

    /**
     * Insert the capability when absent; return its id either way.
     */
    public function ensure(string $key, ?string $description = null): int;

    public function grantToRole(int $roleId, int $capabilityId): void;

    public function revokeFromRole(int $roleId, int $capabilityId): void;
}