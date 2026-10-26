<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

use CMS\Modules\Identity\Entity\User;

/**
 * Authentication service: password login by username OR email, logout.
 *
 * Implementations must never reveal whether the identifier or the password
 * was wrong. Both failures raise the same exception type with the same
 * public message.
 */
interface AuthenticationServiceInterface
{
    /**
     * Attempt to authenticate within the given site.
     *
     * @param string $identifier Username or email address.
     * @param string $password   Plaintext candidate password.
     * @param int    $siteId     Site the credential is scoped to.
     *
     * @throws \CMS\Modules\Identity\Exceptions\InvalidCredentialsException
     * @throws \CMS\Modules\Identity\Exceptions\AccountDisabledException
     */
    public function login(string $identifier, string $password, int $siteId): User;

    /**
     * Invalidate the current authenticated session.
     */
    public function logout(): void;

    /**
     * The currently authenticated user id for this session, if any.
     */
    public function currentUserId(): ?int;
}
