<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

/**
 * Password reset flow.
 *
 * Tokens are generated in plaintext exactly once, returned to the caller for
 * delivery, and stored only as a hash. They expire and are single-use.
 */
interface PasswordResetServiceInterface
{
    /**
     * Issue a reset token for the user identified by username/email on a site.
     *
     * Returns the plaintext token (for email delivery) or null when no
     * matching active user exists. A null return must not be distinguishable
     * to an anonymous caller from a successful issue.
     */
    public function issue(string $identifier, int $siteId): ?string;

    /**
     * Consume a token and set a new password.
     *
     * @throws \CMS\Modules\Identity\Exceptions\InvalidResetTokenException
     * @throws \CMS\Modules\Identity\Exceptions\ExpiredResetTokenException
     */
    public function consume(string $token, string $newPassword, int $siteId): int;
}