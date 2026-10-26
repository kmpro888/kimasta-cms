<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of password reset token persistence.
 *
 * Only token HASHES are stored. A database dump therefore cannot be replayed
 * to reset an account password, which is the whole point of hashing here — the
 * token is high-entropy, so a fast hash is sufficient and correct.
 */
final class PdoPasswordResetRepository implements PasswordResetRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(
        int $siteId,
        int $userId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): int {
        return $this->connection->insert(
            'INSERT INTO password_resets (site_id, user_id, token_hash, expires_at, created_at) '
            . 'VALUES (:site_id, :user_id, :token_hash, :expires_at, :created_at)',
            [
                'site_id' => $siteId,
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $this->format($expiresAt),
                'created_at' => $this->format($createdAt),
            ]
        );
    }

    public function findUsableByHash(string $tokenHash, int $siteId): ?array
    {
        $row = $this->connection->selectOne(
            'SELECT id, user_id, expires_at, used_at FROM password_resets '
            . 'WHERE token_hash = :token_hash AND site_id = :site_id AND used_at IS NULL LIMIT 1',
            ['token_hash' => $tokenHash, 'site_id' => $siteId]
        );

        if ($row === null) {
            return null;
        }

        $expiresAt = $this->toDate($row['expires_at']);

        if ($expiresAt === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'expires_at' => $expiresAt,
            'used_at' => $this->toDate($row['used_at']),
        ];
    }

    public function markUsed(int $id, int $siteId, DateTimeImmutable $usedAt): bool
    {
        // The `used_at IS NULL` predicate makes consumption atomic: two
        // concurrent submissions of the same token produce exactly one winner.
        return $this->connection->affect(
            'UPDATE password_resets SET used_at = :used_at '
            . 'WHERE id = :id AND site_id = :site_id AND used_at IS NULL',
            ['used_at' => $this->format($usedAt), 'id' => $id, 'site_id' => $siteId]
        ) > 0;
    }

    public function invalidateForUser(int $userId, int $siteId, DateTimeImmutable $at): void
    {
        $this->connection->affect(
            'UPDATE password_resets SET used_at = :at '
            . 'WHERE user_id = :user_id AND site_id = :site_id AND used_at IS NULL',
            ['at' => $this->format($at), 'user_id' => $userId, 'site_id' => $siteId]
        );
    }

    public function findHashById(int $id, int $siteId): ?string
    {
        $value = $this->connection->scalar(
            'SELECT token_hash FROM password_resets WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $id, 'site_id' => $siteId]
        );

        return $value === null ? null : (string) $value;
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
