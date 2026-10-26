<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

use CMS\Modules\Identity\Entity\User;

/**
 * Authorisation service: resolves role -> capability and answers can() checks.
 *
 * Role logic is data driven: capabilities come from the database
 * (role_capabilities joined through user_roles), never from hardcoded role
 * name comparisons.
 */
interface AuthorizationServiceInterface
{
    /**
     * Resolved capability keys for a user, scoped to the user's own site.
     *
     * @return list<string>
     */
    public function capabilitiesFor(User $user): array;

    /**
     * Does this user hold the capability on their site?
     */
    public function can(User $user, string $capability, mixed $resource = null): bool;

    /**
     * Resolved capability keys for a site-independent anonymous actor.
     *
     * @return list<string>
     */
    public function capabilitiesForAnonymous(int $siteId): array;
}