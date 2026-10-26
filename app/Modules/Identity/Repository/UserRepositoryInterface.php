<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Modules\Identity\Entity\User;
use DateTimeImmutable;

/**
 * User persistence.
 *
 * Every lookup is site-scoped: a user id from another site must resolve to
 * null, never to the foreign row.
 */
interface UserRepositoryInterface
{
    public function findById(int $id, int $siteId): ?User;

    public function findByUsername(string $username, int $siteId): ?User;

    public function findByEmail(string $email, int $siteId): ?User;

    /**
     * Login lookup: match either username or email, case-insensitively.
     */
    public function findByIdentifier(string $identifier, int $siteId): ?User;

    /** @return list<User> */
    public function listBySite(int $siteId): array;

    public function create(
        int $siteId,
        string $username,
        string $email,
        string $passwordHash,
        string $displayName,
        string $status,
    ): User;

    public function updatePasswordHash(int $id, int $siteId, string $passwordHash, DateTimeImmutable $updatedAt): void;

    public function touchLastLogin(int $id, int $siteId, DateTimeImmutable $at): void;

    public function updateProfile(
        int $id,
        int $siteId,
        string $username,
        string $email,
        string $displayName,
        string $status,
        DateTimeImmutable $updatedAt,
    ): void;

    public function delete(int $id, int $siteId): bool;
}
