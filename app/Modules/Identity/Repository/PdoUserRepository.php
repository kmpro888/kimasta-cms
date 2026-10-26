<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Identity\Entity\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of user persistence.
 *
 * Every statement carries `site_id = :site_id`. That is not decoration: it is
 * what makes a foreign user id resolve to null instead of to another tenant's
 * row, including for UPDATE and DELETE where a forgotten predicate would be a
 * silent cross-site write.
 */
final class PdoUserRepository implements UserRepositoryInterface
{
    private const COLUMNS = 'id, site_id, username, email, password_hash, display_name, '
        . 'status, last_login_at, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id, int $siteId): ?User
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $id, 'site_id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByUsername(string $username, int $siteId): ?User
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE site_id = :site_id AND username = :username LIMIT 1',
            ['site_id' => $siteId, 'username' => $username]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email, int $siteId): ?User
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE site_id = :site_id AND email = :email LIMIT 1',
            ['site_id' => $siteId, 'email' => $email]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByIdentifier(string $identifier, int $siteId): ?User
    {
        // Single indexed lookup for either credential form, so login costs one
        // query instead of two. Native prepared statements forbid reusing a
        // named placeholder, so the identifier is bound twice under two names.
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users '
            . 'WHERE site_id = :site_id AND (username = :username OR email = :email) LIMIT 1',
            ['site_id' => $siteId, 'username' => $identifier, 'email' => $identifier]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function listBySite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE site_id = :site_id ORDER BY id ASC',
            ['site_id' => $siteId]
        );

        return array_map(fn (array $row): User => $this->hydrate($row), $rows);
    }

    public function create(
        int $siteId,
        string $username,
        string $email,
        string $passwordHash,
        string $displayName,
        string $status,
    ): User {
        $now = $this->now();

        $id = $this->connection->insert(
            'INSERT INTO users (site_id, username, email, password_hash, display_name, status, created_at, updated_at) '
            . 'VALUES (:site_id, :username, :email, :password_hash, :display_name, :status, :created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'username' => $username,
                'email' => $email,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $user = $this->findById($id, $siteId);

        if ($user === null) {
            throw new \RuntimeException('User row disappeared immediately after insert.');
        }

        return $user;
    }

    public function updatePasswordHash(int $id, int $siteId, string $passwordHash, DateTimeImmutable $updatedAt): void
    {
        $this->connection->affect(
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at '
            . 'WHERE id = :id AND site_id = :site_id',
            [
                'password_hash' => $passwordHash,
                'updated_at' => $this->format($updatedAt),
                'id' => $id,
                'site_id' => $siteId,
            ]
        );
    }

    public function touchLastLogin(int $id, int $siteId, DateTimeImmutable $at): void
    {
        $this->connection->affect(
            'UPDATE users SET last_login_at = :at WHERE id = :id AND site_id = :site_id',
            ['at' => $this->format($at), 'id' => $id, 'site_id' => $siteId]
        );
    }

    public function updateProfile(
        int $id,
        int $siteId,
        string $username,
        string $email,
        string $displayName,
        string $status,
        DateTimeImmutable $updatedAt,
    ): void {
        $this->connection->affect(
            'UPDATE users SET username = :username, email = :email, display_name = :display_name, '
            . 'status = :status, updated_at = :updated_at WHERE id = :id AND site_id = :site_id',
            [
                'username' => $username,
                'email' => $email,
                'display_name' => $displayName,
                'status' => $status,
                'updated_at' => $this->format($updatedAt),
                'id' => $id,
                'site_id' => $siteId,
            ]
        );
    }

    public function delete(int $id, int $siteId): bool
    {
        return $this->connection->affect(
            'DELETE FROM users WHERE id = :id AND site_id = :site_id',
            ['id' => $id, 'site_id' => $siteId]
        ) > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            displayName: (string) $row['display_name'],
            status: (string) $row['status'],
            lastLoginAt: $this->toDate($row['last_login_at'] ?? null),
            createdAt: $this->toDate($row['created_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            updatedAt: $this->toDate($row['updated_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
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

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
