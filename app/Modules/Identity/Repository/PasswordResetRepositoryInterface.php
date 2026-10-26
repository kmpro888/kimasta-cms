<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use DateTimeImmutable;

/**
 * Password reset token persistence.
 *
 * Only token hashes are ever stored. Lookups are by hash, never by plaintext.
 */
interface PasswordResetRepositoryInterface
{
    /**
     * Persist a reset request for a user on a site.
     *
     * @return int inserted row id
     */
    public function create(
        int $siteId,
        int $userId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): int;

    /**
     * Find a live (unused) reset row by token hash, scoped to a site.
     *
     * @return array{id: int, user_id: int, expires_at: DateTimeImmutable, used_at: ?DateTimeImmutable}|null
     */
    public function findUsableByHash(string $tokenHash, int $siteId): ?array;

    /**
     * Mark a reset row consumed. Returns false when it was already used.
     */
    public function markUsed(int $id, int $siteId, DateTimeImmutable $usedAt): bool;

    /**
     * Invalidate every outstanding token for a user (e.g. after a reset).
     */
    public function invalidateForUser(int $userId, int $siteId, DateTimeImmutable $at): void;

    public function findHashById(int $id, int $siteId): ?string;
}