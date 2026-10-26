<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

use CMS\Shared\Contracts\ActorInterface;

/**
 * Builds the acting principal for the current request.
 *
 * Implementations read the authenticated user id from the session (if any),
 * resolve capabilities once, and return an immutable ActorInterface.
 */
interface ActorProviderInterface
{
    /**
     * Actor for the current request within a resolved site context.
     */
    public function actorForSite(int $siteId): ActorInterface;

    /**
     * Actor for an authenticated user id, or an anonymous actor when the
     * user does not exist on the site.
     */
    public function actorForUser(?int $userId, int $siteId): ActorInterface;
}