<?php

declare(strict_types=1);

namespace CMS\Modules\Audit\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Audit\Entity\AuditEntry;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of the audit trail.
 *
 * Filters are built from a fixed set of keys, never from request input directly: `action` is matched
 * as an exact value or a `prefix.` (so `user.` finds every user action), and the column list is a
 * constant.
 */
final class PdoAuditLogRepository implements AuditLogRepositoryInterface
{
    private const COLUMNS = 'id, site_id, actor_id, actor_name, action, subject_type, subject_id, '
        . 'context_json, ip, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function append(
        int $siteId,
        ?int $actorId,
        string $actorName,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        array $context,
        ?string $ip,
    ): void {
        $this->connection->statement(
            'INSERT INTO audit_log (site_id, actor_id, actor_name, action, subject_type, subject_id, '
            . 'context_json, ip, created_at) VALUES (:site_id, :actor_id, :actor_name, :action, '
            . ':subject_type, :subject_id, :context_json, :ip, :created_at)',
            [
                'site_id' => $siteId,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip' => $ip,
                'created_at' => $this->now(),
            ]
        );
    }

    public function listForSite(int $siteId, array $filters = []): array
    {
        [$where, $bindings] = $this->where($siteId, $filters);

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . ' FROM audit_log WHERE ' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bindings
        );

        return array_map(fn (array $row): AuditEntry => $this->hydrate($row), $rows);
    }

    public function countForSite(int $siteId, array $filters = []): int
    {
        [$where, $bindings] = $this->where($siteId, $filters);

        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM audit_log WHERE ' . $where,
            $bindings
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(int $siteId, array $filters): array
    {
        $conditions = ['site_id = :site_id'];
        $bindings = ['site_id' => $siteId];

        $action = trim((string) ($filters['action'] ?? ''));

        if ($action !== '') {
            if (str_ends_with($action, '.')) {
                $conditions[] = 'action LIKE :action_prefix';
                $bindings['action_prefix'] = rtrim($action, '.') . '.%';
            } else {
                $conditions[] = 'action = :action';
                $bindings['action'] = $action;
            }
        }

        $actor = (int) ($filters['actor_id'] ?? 0);

        if ($actor > 0) {
            $conditions[] = 'actor_id = :actor_id';
            $bindings['actor_id'] = $actor;
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditEntry
    {
        $context = [];

        if (is_string($row['context_json'] ?? null) && $row['context_json'] !== '') {
            $decoded = json_decode($row['context_json'], true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return new AuditEntry(
            (int) $row['id'],
            (int) $row['site_id'],
            $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
            (string) $row['actor_name'],
            (string) $row['action'],
            $row['subject_type'] !== null ? (string) $row['subject_type'] : null,
            $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
            $context,
            $row['ip'] !== null ? (string) $row['ip'] : null,
            new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        );
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
