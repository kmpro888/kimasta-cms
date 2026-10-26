<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Service;

use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Exceptions\ExpiredResetTokenException;
use CMS\Modules\Identity\Exceptions\InvalidResetTokenException;
use CMS\Modules\Identity\Repository\PasswordResetRepositoryInterface;
use CMS\Modules\Identity\Support\PasswordPolicy;
use CMS\Modules\Identity\Repository\UserRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;
use RuntimeException;

/**
 * Password reset issue/consume flow.
 *
 * Design decisions worth keeping:
 *  - The plaintext token is returned to the caller exactly once, for delivery
 *    by email. Only a SHA-256 hash is stored, so a database leak cannot be
 *    replayed to take over accounts.
 *  - `issue()` returns null for an unknown or disabled account instead of
 *    throwing, and the controller is expected to show the same "if that account
 *    exists, an email is on its way" message either way. That is what stops the
 *    endpoint from being an account-enumeration oracle.
 *  - Consumption is atomic via `UPDATE ... WHERE used_at IS NULL`, so two
 *    concurrent submissions of one token produce exactly one winner.
 */
final class PasswordResetService implements PasswordResetServiceInterface
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordResetRepositoryInterface $resets,
        private readonly ClockInterface $clock,
        private readonly int $ttlMinutes = 60,
    ) {
    }

    public function issue(string $identifier, int $siteId): ?string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $user = $this->users->findByIdentifier($identifier, $siteId);

        if ($user === null || !$user->isActive() || $user->id === null) {
            return null;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now = $this->clock->now();

        $this->resets->create(
            siteId: $siteId,
            userId: $user->id,
            tokenHash: $this->hash($token),
            expiresAt: $now->modify('+' . max(1, $this->ttlMinutes) . ' minutes'),
            createdAt: $now,
        );

        return $token;
    }

    public function consume(string $token, string $newPassword, int $siteId): int
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidResetTokenException();
        }

        $row = $this->resets->findUsableByHash($this->hash($token), $siteId);

        if ($row === null) {
            throw new InvalidResetTokenException();
        }

        $now = $this->clock->now();

        if ($row['expires_at'] < $now) {
            // Burn it so an expired token cannot be retried forever.
            $this->resets->markUsed($row['id'], $siteId, $now);

            throw new ExpiredResetTokenException();
        }

        // A reset is one of the two ways a person chooses a new password; the same floor
        // applies here as in the change-password path, so neither can be the weak door.
        // Reported as an invalid token like every other failure, because this endpoint must
        // not reveal whether a token was real.
        if ($newPassword === '' || mb_strlen($newPassword) < PasswordPolicy::MIN_LENGTH) {
            throw new InvalidResetTokenException();
        }

        // Atomic single-use claim. Losing the race raises the same generic
        // error as an unknown token.
        if (!$this->resets->markUsed($row['id'], $siteId, $now)) {
            throw new InvalidResetTokenException();
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash the new password.');
        }

        $this->users->updatePasswordHash($row['user_id'], $siteId, $hash, $now);

        // Any sibling token issued before this reset is now void.
        $this->resets->invalidateForUser($row['user_id'], $siteId, $now);

        return $row['user_id'];
    }

    /**
     * Hash a plaintext reset token for storage and lookup.
     *
     * SHA-256 is correct here: the token already carries 256 bits of entropy, so
     * a slow KDF would only make lookups expensive without adding resistance to
     * guessing.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
