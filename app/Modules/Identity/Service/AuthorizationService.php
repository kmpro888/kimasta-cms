<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Service;

use CMS\Modules\Identity\Contracts\AuthorizationServiceInterface;
use CMS\Modules\Identity\Entity\User;
use CMS\Modules\Identity\Repository\RoleRepositoryInterface;

/**
 * Capability authorisation.
 *
 * Role names never appear in a decision. A user's capabilities are read from
 * role_capabilities joined through user_roles, so an administrator can rename
 * or re-point a role without a code change, and a new role works immediately.
 */
final class AuthorizationService implements AuthorizationServiceInterface
{
    public function __construct(private readonly RoleRepositoryInterface $roles)
    {
    }

    public function capabilitiesFor(User $user): array
    {
        $userId = $user->id;

        if ($userId === null || $userId <= 0) {
            return [];
        }

        return $this->roles->capabilitiesForUser($userId, $user->siteId);
    }

    public function can(User $user, string $capability, mixed $resource = null): bool
    {
        if ($capability === '' || !$user->isActive()) {
            return false;
        }

        // Resource-scoped rules belong to the module that owns the resource;
        // this answers the site-wide question only.
        return in_array($capability, $this->capabilitiesFor($user), true);
    }

    public function capabilitiesForAnonymous(int $siteId): array
    {
        // Anonymous visitors hold no capabilities. Deliberately not configurable
        // here: "everyone is an editor" must never become a flag.
        return [];
    }
}
