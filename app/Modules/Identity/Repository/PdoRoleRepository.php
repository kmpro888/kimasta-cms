<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Identity\Entity\Role;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of role persistence and capability resolution.
 *
 * Capabilities are resolved with ONE joined query rather than loading roles and
 * then querying capabilities per role. That keeps an authorisation check at a
 * single round trip no matter how many roles a user holds.
 */
final class PdoRoleRepository implements RoleRepositoryInterface
{
    private const COLUMNS = 'id, site_id, name, slug, is_system, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id, int $siteId): ?Role
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM roles WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $id, 'site_id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug, int $siteId): ?Role
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM roles WHERE site_id = :site_id AND slug = :slug LIMIT 1',
            ['site_id' => $siteId, 'slug' => $slug]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function listBySite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM roles WHERE site_id = :site_id ORDER BY id ASC',
            ['site_id' => $siteId]
        );

        return array_map(fn (array $row): Role => $this->hydrate($row), $rows);
    }

    public function listForUser(int $userId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT r.id, r.site_id, r.name, r.slug, r.is_system, r.created_at '
            . 'FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :user_id AND r.site_id = :site_id ORDER BY r.id ASC',
            ['user_id' => $userId, 'site_id' => $siteId]
        );

        return array_map(fn (array $row): Role => $this->hydrate($row), $rows);
    }

    public function create(int $siteId, string $name, string $slug, bool $isSystem): Role
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $id = $this->connection->insert(
            'INSERT INTO roles (site_id, name, slug, is_system, created_at) '
            . 'VALUES (:site_id, :name, :slug, :is_system, :created_at)',
            [
                'site_id' => $siteId,
                'name' => $name,
                'slug' => $slug,
                'is_system' => $isSystem ? 1 : 0,
                'created_at' => $now,
            ]
        );

        $role = $this->findById($id, $siteId);

        if ($role === null) {
            throw new \RuntimeException('Role row disappeared immediately after insert.');
        }

        return $role;
    }

    public function assignToUser(int $userId, int $roleId): void
    {
        // Idempotent: assigning an already-held role is a no-op, not an error.
        $this->connection->affect(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $roleId]
        );
    }

    public function revokeFromUser(int $userId, int $roleId): void
    {
        $this->connection->affect(
            'DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id',
            ['user_id' => $userId, 'role_id' => $roleId]
        );
    }

    public function capabilitiesForUser(int $userId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT DISTINCT c.capability_key '
            . 'FROM user_roles ur '
            . 'INNER JOIN roles r ON r.id = ur.role_id '
            . 'INNER JOIN role_capabilities rc ON rc.role_id = r.id '
            . 'INNER JOIN capabilities c ON c.id = rc.capability_id '
            . 'WHERE ur.user_id = :user_id AND r.site_id = :site_id '
            . 'ORDER BY c.capability_key ASC',
            ['user_id' => $userId, 'site_id' => $siteId]
        );

        return array_map(static fn (array $row): string => (string) $row['capability_key'], $rows);
    }

    public function capabilityKeysForRole(int $roleId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT c.capability_key '
            . 'FROM role_capabilities rc '
            . 'INNER JOIN roles r ON r.id = rc.role_id '
            . 'INNER JOIN capabilities c ON c.id = rc.capability_id '
            . 'WHERE rc.role_id = :role_id AND r.site_id = :site_id '
            . 'ORDER BY c.capability_key ASC',
            ['role_id' => $roleId, 'site_id' => $siteId]
        );

        return array_map(static fn (array $row): string => (string) $row['capability_key'], $rows);
    }

    public function usersWithCapability(string $capability, int $siteId): array
    {
        // Active users only: notifying a suspended account is noise, and the join on
        // users.site_id keeps the lookup inside the tenant.
        $rows = $this->connection->select(
            'SELECT DISTINCT u.id '
            . 'FROM users u '
            . 'INNER JOIN user_roles ur ON ur.user_id = u.id '
            . 'INNER JOIN role_capabilities rc ON rc.role_id = ur.role_id '
            . 'INNER JOIN roles r ON r.id = ur.role_id '
            . 'INNER JOIN capabilities c ON c.id = rc.capability_id '
            . 'WHERE u.site_id = :site_id AND r.site_id = :site2 AND c.capability_key = :capability '
            . 'AND u.status = :status '
            . 'ORDER BY u.id ASC',
            [
                'site_id' => $siteId,
                'site2' => $siteId,
                'capability' => $capability,
                'status' => 'active',
            ]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Role
    {
        return new Role(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            isSystem: (bool) (int) $row['is_system'],
            createdAt: $this->toDate($row['created_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
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
}
