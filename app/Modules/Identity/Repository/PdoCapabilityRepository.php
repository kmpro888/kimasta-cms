<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Identity\Entity\Capability;

/**
 * PDO implementation of the global capability registry.
 *
 * Capabilities are global by design: they name an ability, not a tenant. The
 * tenant boundary is enforced in role_capabilities/user_roles, where every role
 * carries a site_id.
 */
final class PdoCapabilityRepository implements CapabilityRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByKey(string $key): ?Capability
    {
        $row = $this->connection->selectOne(
            'SELECT id, capability_key, description FROM capabilities WHERE capability_key = :key LIMIT 1',
            ['key' => $key]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function listAll(): array
    {
        $rows = $this->connection->select(
            'SELECT id, capability_key, description FROM capabilities ORDER BY capability_key ASC'
        );

        return array_map(fn (array $row): Capability => $this->hydrate($row), $rows);
    }

    public function ensure(string $key, ?string $description = null): int
    {
        // One statement, no read-then-write race. LAST_INSERT_ID() is only
        // meaningful on the insert branch, so the id is read back explicitly.
        $this->connection->affect(
            'INSERT INTO capabilities (capability_key, description) VALUES (:key, :description) '
            . 'ON DUPLICATE KEY UPDATE capability_key = capability_key',
            ['key' => $key, 'description' => $description]
        );

        $id = $this->connection->scalar(
            'SELECT id FROM capabilities WHERE capability_key = :key LIMIT 1',
            ['key' => $key]
        );

        if ($id === null) {
            throw new \RuntimeException('Capability could not be resolved after upsert: ' . $key);
        }

        return (int) $id;
    }

    public function grantToRole(int $roleId, int $capabilityId): void
    {
        $this->connection->affect(
            'INSERT IGNORE INTO role_capabilities (role_id, capability_id) VALUES (:role_id, :capability_id)',
            ['role_id' => $roleId, 'capability_id' => $capabilityId]
        );
    }

    public function revokeFromRole(int $roleId, int $capabilityId): void
    {
        $this->connection->affect(
            'DELETE FROM role_capabilities WHERE role_id = :role_id AND capability_id = :capability_id',
            ['role_id' => $roleId, 'capability_id' => $capabilityId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Capability
    {
        return new Capability(
            id: (int) $row['id'],
            key: (string) $row['capability_key'],
            description: isset($row['description']) && $row['description'] !== null
                ? (string) $row['description']
                : null,
        );
    }
}
