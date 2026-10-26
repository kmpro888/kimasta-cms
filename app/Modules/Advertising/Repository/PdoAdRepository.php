<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Advertising\Entity\Ad;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of ads and their targeting rules.
 */
final class PdoAdRepository implements AdRepositoryInterface
{
    private const COLUMNS = 'id, site_id, name, placement, body_html, target_url, is_active, '
        . 'priority, starts_at, ends_at, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function activeForSite(int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM ads '
            . 'WHERE site_id = :site_id AND is_active = 1 '
            . 'ORDER BY priority DESC, id ASC',
            ['site_id' => $siteId]
        );

        return $this->hydrateMany($rows, $siteId);
    }

    public function listForSite(int $siteId, ?string $placement = null): array
    {
        $clauses = ['site_id = :site_id'];
        $bindings = ['site_id' => $siteId];

        if ($placement !== null && $placement !== '') {
            $clauses[] = 'placement = :placement';
            $bindings['placement'] = $placement;
        }

        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM ads WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY priority DESC, id ASC',
            $bindings
        );

        return $this->hydrateMany($rows, $siteId);
    }

    public function find(int $adId, int $siteId): ?Ad
    {
        $row = $this->connection->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM ads WHERE id = :id AND site_id = :site_id LIMIT 1',
            ['id' => $adId, 'site_id' => $siteId]
        );

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $this->rulesFor($adId));
    }

    public function create(int $siteId, array $attributes, array $rules): Ad
    {
        $now = $this->now();

        $this->connection->affect(
            'INSERT INTO ads (site_id, name, placement, body_html, target_url, is_active, priority, '
            . 'starts_at, ends_at, created_at, updated_at) '
            . 'VALUES (:site_id, :name, :placement, :body_html, :target_url, :is_active, :priority, '
            . ':starts_at, :ends_at, :created_at, :updated_at)',
            [
                'site_id' => $siteId,
                'name' => (string) $attributes['name'],
                'placement' => (string) $attributes['placement'],
                'body_html' => (string) $attributes['body_html'],
                'target_url' => $attributes['target_url'] ?? null,
                'is_active' => ($attributes['is_active'] ?? true) ? 1 : 0,
                'priority' => (int) ($attributes['priority'] ?? 0),
                'starts_at' => $attributes['starts_at'] ?? null,
                'ends_at' => $attributes['ends_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $adId = (int) $this->connection->lastInsertId();
        $this->replaceRules($adId, $rules);

        $created = $this->find($adId, $siteId);

        if ($created === null) {
            throw new \RuntimeException('Ad was inserted but could not be read back.');
        }

        return $created;
    }

    public function update(int $adId, int $siteId, array $attributes, ?array $rules): Ad
    {
        $existing = $this->find($adId, $siteId);

        if ($existing === null) {
            throw new \RuntimeException('Ad not found for update: ' . $adId);
        }

        $this->connection->affect(
            'UPDATE ads SET name = :name, placement = :placement, body_html = :body_html, '
            . 'target_url = :target_url, is_active = :is_active, priority = :priority, '
            . 'starts_at = :starts_at, ends_at = :ends_at, updated_at = :updated_at '
            . 'WHERE id = :id AND site_id = :site_id',
            [
                'name' => (string) ($attributes['name'] ?? $existing->name),
                'placement' => (string) ($attributes['placement'] ?? $existing->placement),
                'body_html' => (string) ($attributes['body_html'] ?? $existing->bodyHtml),
                'target_url' => array_key_exists('target_url', $attributes)
                    ? $attributes['target_url']
                    : $existing->targetUrl,
                'is_active' => array_key_exists('is_active', $attributes)
                    ? ($attributes['is_active'] ? 1 : 0)
                    : ($existing->isActive ? 1 : 0),
                'priority' => (int) ($attributes['priority'] ?? $existing->priority),
                'starts_at' => array_key_exists('starts_at', $attributes)
                    ? $attributes['starts_at']
                    : $existing->startsAt?->format('Y-m-d H:i:s'),
                'ends_at' => array_key_exists('ends_at', $attributes)
                    ? $attributes['ends_at']
                    : $existing->endsAt?->format('Y-m-d H:i:s'),
                'updated_at' => $this->now(),
                'id' => $adId,
                'site_id' => $siteId,
            ]
        );

        if ($rules !== null) {
            $this->replaceRules($adId, $rules);
        }

        $updated = $this->find($adId, $siteId);

        if ($updated === null) {
            throw new \RuntimeException('Ad disappeared during update: ' . $adId);
        }

        return $updated;
    }

    public function delete(int $adId, int $siteId): bool
    {
        // ad_rules cascade on the foreign key.
        return $this->connection->affect(
            'DELETE FROM ads WHERE id = :id AND site_id = :site_id',
            ['id' => $adId, 'site_id' => $siteId]
        ) > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Ad>
     */
    private function hydrateMany(array $rows, int $siteId): array
    {
        if ($rows === []) {
            return [];
        }

        // One query for every ad's rules, not one per ad.
        $adIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $rules = $this->rulesForMany($adIds);

        return array_map(
            fn (array $row): Ad => $this->hydrate($row, $rules[(int) $row['id']] ?? []),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, list<string>> $rules
     */
    private function hydrate(array $row, array $rules): Ad
    {
        return new Ad(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            name: (string) $row['name'],
            placement: (string) $row['placement'],
            bodyHtml: (string) $row['body_html'],
            targetUrl: isset($row['target_url']) && $row['target_url'] !== '' ? (string) $row['target_url'] : null,
            isActive: (bool) (int) $row['is_active'],
            priority: (int) $row['priority'],
            startsAt: $this->toDate($row['starts_at'] ?? null),
            endsAt: $this->toDate($row['ends_at'] ?? null),
            rules: $rules,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function rulesFor(int $adId): array
    {
        return $this->rulesForMany([$adId])[$adId] ?? [];
    }

    /**
     * @param list<int> $adIds
     *
     * @return array<int, array<string, list<string>>>
     */
    private function rulesForMany(array $adIds): array
    {
        if ($adIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach (array_values($adIds) as $index => $id) {
            $key = 'ad_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $rows = $this->connection->select(
            'SELECT ad_id, rule_type, rule_value FROM ad_rules '
            . 'WHERE ad_id IN (' . implode(', ', $placeholders) . ')',
            $bindings
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['ad_id']][(string) $row['rule_type']][] = (string) $row['rule_value'];
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $rules
     */
    private function replaceRules(int $adId, array $rules): void
    {
        $this->connection->affect('DELETE FROM ad_rules WHERE ad_id = :ad_id', ['ad_id' => $adId]);

        foreach ($rules as $type => $values) {
            foreach (array_unique($values) as $value) {
                $this->connection->affect(
                    'INSERT INTO ad_rules (ad_id, rule_type, rule_value) VALUES (:ad_id, :type, :value)',
                    ['ad_id' => $adId, 'type' => (string) $type, 'value' => (string) $value]
                );
            }
        }
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
