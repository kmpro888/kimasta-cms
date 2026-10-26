<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Repository;

use CMS\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of domain mapping persistence.
 *
 * `domain` is globally unique at the database level, which is the real
 * guarantee that one host cannot be steered onto two tenants. The service
 * layer pre-checks to return a clean 409, but the constraint is what makes the
 * invariant true under concurrency.
 */
final class PdoDomainRepository implements DomainRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findSiteIdByDomain(string $domain): ?int
    {
        $value = $this->connection->scalar(
            'SELECT site_id FROM site_domains WHERE domain = :domain LIMIT 1',
            ['domain' => $domain]
        );

        if ($value !== null) {
            return (int) $value;
        }

        // Fall back to the sites.primary_domain column so a freshly created
        // site resolves before its mapping row is written.
        $value = $this->connection->scalar(
            'SELECT id FROM sites WHERE primary_domain = :domain LIMIT 1',
            ['domain' => $domain]
        );

        return $value === null ? null : (int) $value;
    }

    public function domainsForSite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT domain FROM site_domains WHERE site_id = :site_id ORDER BY is_primary DESC, domain ASC',
            ['site_id' => $siteId]
        );

        return array_map(static fn (array $row): string => (string) $row['domain'], $rows);
    }

    public function primaryDomainForSite(int $siteId): ?string
    {
        $value = $this->connection->scalar(
            'SELECT domain FROM site_domains WHERE site_id = :site_id AND is_primary = 1 LIMIT 1',
            ['site_id' => $siteId]
        );

        if ($value !== null) {
            return (string) $value;
        }

        $value = $this->connection->scalar(
            'SELECT primary_domain FROM sites WHERE id = :id LIMIT 1',
            ['id' => $siteId]
        );

        return $value === null ? null : (string) $value;
    }

    public function add(int $siteId, string $domain, bool $isPrimary): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($isPrimary) {
            // Only one primary per site.
            $this->connection->affect(
                'UPDATE site_domains SET is_primary = 0 WHERE site_id = :site_id',
                ['site_id' => $siteId]
            );
        }

        $this->connection->affect(
            'INSERT INTO site_domains (site_id, domain, is_primary, created_at) '
            . 'VALUES (:site_id, :domain, :is_primary, :created_at)',
            [
                'site_id' => $siteId,
                'domain' => $domain,
                'is_primary' => $isPrimary ? 1 : 0,
                'created_at' => $now,
            ]
        );

        if ($isPrimary) {
            $this->connection->affect(
                'UPDATE sites SET primary_domain = :domain, updated_at = :updated_at WHERE id = :id',
                ['domain' => $domain, 'updated_at' => $now, 'id' => $siteId]
            );
        }
    }

    public function remove(int $siteId, string $domain): bool
    {
        $affected = $this->connection->affect(
            'DELETE FROM site_domains WHERE site_id = :site_id AND domain = :domain',
            ['site_id' => $siteId, 'domain' => $domain]
        );

        return $affected > 0;
    }

    public function allMappings(): array
    {
        $mappings = [];

        // Mapping rows win because they carry an explicit is_primary flag.
        $rows = $this->connection->select(
            'SELECT domain, site_id FROM site_domains ORDER BY is_primary DESC, domain ASC'
        );

        foreach ($rows as $row) {
            $mappings[(string) $row['domain']] = (int) $row['site_id'];
        }

        // Then any primary_domain not yet mirrored as a mapping row.
        $sites = $this->connection->select(
            'SELECT primary_domain, id FROM sites ORDER BY id ASC'
        );

        foreach ($sites as $row) {
            $domain = (string) $row['primary_domain'];

            if ($domain !== '' && !isset($mappings[$domain])) {
                $mappings[$domain] = (int) $row['id'];
            }
        }

        return $mappings;
    }
}
