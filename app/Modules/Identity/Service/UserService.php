<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Service;

use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Entity\User;
use CMS\Modules\Identity\Exceptions\DuplicateUserException;
use CMS\Modules\Identity\Repository\UserRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * User management, always scoped to an explicit site.
 *
 * Uniqueness is per site, so the same email may legitimately exist on two
 * tenants. The duplicate pre-check produces a clean domain error; the unique
 * key on (site_id, email) is what actually holds under concurrency.
 */
final class UserService implements UserServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ClockInterface $clock,
    ) {
    }

    public function find(int $userId, int $siteId): ?User
    {
        return $this->users->findById($userId, $siteId);
    }

    public function listForSite(int $siteId): array
    {
        return $this->users->listBySite($siteId);
    }

    public function create(int $siteId, array $data): User
    {
        $username = $this->requireString($data, 'username');
        $email = $this->requireString($data, 'email');
        $password = $this->requireString($data, 'password');
        $displayName = isset($data['display_name']) && is_string($data['display_name']) && $data['display_name'] !== ''
            ? $data['display_name']
            : $username;
        $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : User::STATUS_ACTIVE;

        if (!in_array($status, [User::STATUS_ACTIVE, User::STATUS_DISABLED], true)) {
            throw new InvalidArgumentException('Unknown user status: ' . $status);
        }

        if ($this->users->findByUsername($username, $siteId) !== null) {
            throw new DuplicateUserException('username');
        }

        if ($this->users->findByEmail($email, $siteId) !== null) {
            throw new DuplicateUserException('email');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash the password.');
        }

        try {
            return $this->users->create($siteId, $username, $email, $hash, $displayName, $status);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new DuplicateUserException('username or email');
            }

            throw $exception;
        }
    }

    public function update(int $userId, int $siteId, array $data): User
    {
        $existing = $this->users->findById($userId, $siteId);

        if ($existing === null) {
            throw new InvalidArgumentException('User does not exist on this site.');
        }

        $username = isset($data['username']) && is_string($data['username']) && $data['username'] !== ''
            ? $data['username']
            : $existing->username;

        $email = isset($data['email']) && is_string($data['email']) && $data['email'] !== ''
            ? $data['email']
            : $existing->email;

        $displayName = isset($data['display_name']) && is_string($data['display_name']) && $data['display_name'] !== ''
            ? $data['display_name']
            : $existing->displayName;

        $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : $existing->status;

        if (!in_array($status, [User::STATUS_ACTIVE, User::STATUS_DISABLED], true)) {
            throw new InvalidArgumentException('Unknown user status: ' . $status);
        }

        if ($username !== $existing->username) {
            $clash = $this->users->findByUsername($username, $siteId);

            if ($clash !== null && $clash->id !== $userId) {
                throw new DuplicateUserException('username');
            }
        }

        if ($email !== $existing->email) {
            $clash = $this->users->findByEmail($email, $siteId);

            if ($clash !== null && $clash->id !== $userId) {
                throw new DuplicateUserException('email');
            }
        }

        $this->users->updateProfile(
            $userId,
            $siteId,
            $username,
            $email,
            $displayName,
            $status,
            $this->clock->now()
        );

        if (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') {
            $this->changePassword($userId, $siteId, $data['password']);
        }

        $updated = $this->users->findById($userId, $siteId);

        if ($updated === null) {
            throw new InvalidArgumentException('User does not exist on this site.');
        }

        return $updated;
    }

    public function delete(int $userId, int $siteId): bool
    {
        return $this->users->delete($userId, $siteId);
    }

    public function changePassword(int $userId, int $siteId, string $newPassword): void
    {
        if ($newPassword === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash the password.');
        }

        $this->users->updatePasswordHash($userId, $siteId, $hash, $this->clock->now());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new InvalidArgumentException('Missing required field: ' . $key);
        }

        return trim($data[$key]);
    }
}
