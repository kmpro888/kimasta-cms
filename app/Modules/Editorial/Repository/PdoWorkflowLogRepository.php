<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Repository;

use CMS\Core\Database\Connection;
use CMS\Modules\Editorial\Contracts\WorkflowLogRepositoryInterface;
use CMS\Modules\Editorial\Entity\WorkflowLog;
use DateTimeImmutable;
use DateTimeZone;

/**
 * PDO implementation of the workflow audit trail.
 */
final class PdoWorkflowLogRepository implements WorkflowLogRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(
        int $siteId,
        int $contentId,
        int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $note,
    ): WorkflowLog {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $id = $this->connection->insert(
            'INSERT INTO workflow_logs (site_id, content_id, user_id, from_status, to_status, note, created_at) '
            . 'VALUES (:site_id, :content_id, :user_id, :from_status, :to_status, :note, :created_at)',
            [
                'site_id' => $siteId,
                'content_id' => $contentId,
                'user_id' => $userId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $note,
                'created_at' => $now,
            ]
        );

        return new WorkflowLog(
            id: $id,
            siteId: $siteId,
            contentId: $contentId,
            userId: $userId,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            note: $note,
            createdAt: new DateTimeImmutable($now, new DateTimeZone('UTC')),
        );
    }

    public function historyFor(int $contentId, int $siteId): array
    {
        $rows = $this->connection->select(
            'SELECT id, site_id, content_id, user_id, from_status, to_status, note, created_at '
            . 'FROM workflow_logs WHERE site_id = :site_id AND content_id = :content_id '
            . 'ORDER BY id DESC',
            ['site_id' => $siteId, 'content_id' => $contentId]
        );

        return array_map(static fn (array $row): WorkflowLog => new WorkflowLog(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            contentId: (int) $row['content_id'],
            userId: (int) $row['user_id'],
            fromStatus: isset($row['from_status']) ? (string) $row['from_status'] : null,
            toStatus: (string) $row['to_status'],
            note: isset($row['note']) ? (string) $row['note'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        ), $rows);
    }

    public function countFor(int $contentId, int $siteId): int
    {
        return (int) ($this->connection->scalar(
            'SELECT COUNT(*) FROM workflow_logs WHERE site_id = :site_id AND content_id = :content_id',
            ['site_id' => $siteId, 'content_id' => $contentId]
        ) ?? 0);
    }
}
