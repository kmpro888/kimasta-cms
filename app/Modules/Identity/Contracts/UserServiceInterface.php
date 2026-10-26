<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Contracts;

use CMS\Modules\Identity\Entity\User;

/**
 * User management scoped to a site.
 *
 * Every operation takes an explicit site id; implementations must never fall
 * back to a global default. Cross-site access behaves as not-found.
 */
interface UserServiceInterface
{
    /**
     * @return User|null null when the user does not exist on this site.
     */
    public function find(int $userId, int $siteId): ?User;

    /**
     * @return list<User>
     */
    public function listForSite(int $siteId): array;

    /**
     * @param array{username: string, email: string, password: string, display_name: string, status?: string} $data
     */
    public function create(int $siteId, array $data): User;

    /**
     * @param array{username?: string, email?: string, display_name?: string, status?: string, password?: string} $data
     */
    public function update(int $userId, int $siteId, array $data): User;

    public function delete(int $userId, int $siteId): bool;

    public function changePassword(int $userId, int $siteId, string $newPassword): void;
}