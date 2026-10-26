<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Site\Entity\Site;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of site persistence.
 *
 * Explicit column lists everywhere; no SELECT * on a request path. The site row
 * is small and read-mostly, but the same discipline applies so a future schema
 * change cannot silently widen a hot query.
 */
final class PdoSiteRepository implements SiteRepositoryInterface
{
    private const COLUMNS = 'id, name, slug, status, primary_domain, default_locale, '
        . 'timezone, base_url, active_theme, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $siteId): ?Site
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM sites WHERE id = :id LIMIT 1',
            ['id' => $siteId]
        );

        return $row === null ? null : $this->hydrate($row, (string) $row['primary_domain']);
    }

    public function findBySlug(string $slug): ?Site
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM sites WHERE slug = :slug LIMIT 1',
            ['slug' => $slug]
        );

        return $row === null ? null : $this->hydrate($row, (string) $row['primary_domain']);
    }

    public function findByDomain(string $domain): ?Site
    {
        // One indexed lookup on the mapping table; the JOIN keeps it to a
        // single round trip instead of lookup-then-fetch.
        $row = $this->connection->selectOne(
            'SELECT s.id, s.name, s.slug, s.status, s.primary_domain, s.default_locale, '
            . 's.timezone, s.base_url, s.active_theme, s.created_at, s.updated_at '
            . 'FROM site_domains d INNER JOIN sites s ON s.id = d.site_id '
            . 'WHERE d.domain = :domain LIMIT 1',
            ['domain' => $domain]
        );

        if ($row !== null) {
            return $this->hydrate($row, $domain);
        }

        // A site's primary_domain is authoritative even before a mapping row
        // exists, so honour it as a second indexed lookup.
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM sites WHERE primary_domain = :domain LIMIT 1',
            ['domain' => $domain]
        );

        return $row === null ? null : $this->hydrate($row, $domain);
    }

    public function all(): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM sites ORDER BY id ASC'
        );

        $sites = [];

        foreach ($rows as $row) {
            $sites[] = $this->hydrate($row, (string) $row['primary_domain']);
        }

        return $sites;
    }

    public function create(
        string $name,
        string $slug,
        string $primaryDomain,
        string $defaultLocale,
        string $timezone,
        string $baseUrl,
        string $status,
    ): Site {
        $now = $this->now();

        $id = $this->connection->insert(
            'INSERT INTO sites (name, slug, status, primary_domain, default_locale, timezone, base_url, created_at, updated_at) '
            . 'VALUES (:name, :slug, :status, :primary_domain, :default_locale, :timezone, :base_url, :created_at, :updated_at)',
            [
                'name' => $name,
                'slug' => $slug,
                'status' => $status,
                'primary_domain' => $primaryDomain,
                'default_locale' => $defaultLocale,
                'timezone' => $timezone,
                'base_url' => $baseUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $site = $this->findById($id);

        if ($site === null) {
            throw new \RuntimeException('Site row disappeared immediately after insert.');
        }

        return $site;
    }

    public function updateStatus(int $siteId, string $status): void
    {
        $this->connection->affect(
            'UPDATE sites SET status = :status, updated_at = :updated_at WHERE id = :id',
            ['status' => $status, 'updated_at' => $this->now(), 'id' => $siteId]
        );
    }

    public function updateTheme(int $siteId, ?string $theme): void
    {
        $this->connection->affect(
            'UPDATE sites SET active_theme = :theme, updated_at = :updated_at WHERE id = :id',
            ['theme' => $theme, 'updated_at' => $this->now(), 'id' => $siteId]
        );
    }

    public function updatePrimaryDomain(int $siteId, string $domain): void
    {
        $this->connection->affect(
            'UPDATE sites SET primary_domain = :domain, updated_at = :updated_at WHERE id = :id',
            ['domain' => $domain, 'updated_at' => $this->now(), 'id' => $siteId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, string $host): Site
    {
        return new Site(
            siteId: (int) $row['id'],
            siteSlug: (string) $row['slug'],
            siteName: (string) $row['name'],
            hostName: $host,
            locale: (string) $row['default_locale'],
            tz: (string) $row['timezone'],
            siteBaseUrl: (string) $row['base_url'],
            active: (string) $row['status'] === 'active',
            theme: isset($row['active_theme']) ? (string) $row['active_theme'] : null,
            createdAt: $this->toDate($row['created_at'] ?? null),
            updatedAt: $this->toDate($row['updated_at'] ?? null),
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

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
