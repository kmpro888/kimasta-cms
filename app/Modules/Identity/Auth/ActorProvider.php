<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Auth;

use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Repository\RoleRepositoryInterface;
use CMS\Modules\Identity\Repository\UserRepositoryInterface;
use CMS\Shared\Contracts\ActorInterface;

/**
 * Builds the acting principal for a request.
 *
 * The only place that knows how "authenticated user" becomes "capability set".
 * It reads the user, resolves capabilities in one query, and returns an
 * immutable Actor. A user id belonging to another site resolves to an
 * anonymous actor rather than to a foreign tenant's capabilities.
 */
final class ActorProvider implements ActorProviderInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
    ) {
    }

    public function actorForSite(int $siteId): ActorInterface
    {
        return Actor::anonymous($siteId);
    }

    public function actorForUser(?int $userId, int $siteId): ActorInterface
    {
        if ($userId === null || $userId <= 0) {
            return Actor::anonymous($siteId);
        }

        // Site-scoped lookup: a foreign user id is indistinguishable from a
        // nonexistent one, which is exactly the required behaviour.
        $user = $this->users->findById($userId, $siteId);

        if ($user === null || !$user->isActive()) {
            return Actor::anonymous($siteId);
        }

        return new Actor(
            $user->id,
            $siteId,
            $this->roles->capabilitiesForUser($user->id ?? 0, $siteId),
        );
    }
}
