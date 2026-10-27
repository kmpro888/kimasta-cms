<?php

declare(strict_types=1);

namespace CMS\Modules\Audit\Repository;

use CMS\Modules\Audit\Entity\AuditEntry;

/**
 * Storage for the audit trail.
 *
 * Two methods and no more: append, and read. There is deliberately no `update()` and no `delete()`,
 * because a log that can be rewritten is not a log.
 */
interface AuditLogRepositoryInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function append(
        int $siteId,
        ?int $actorId,
        string $actorName,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        array $context,
        ?string $ip,
    ): void;

    /**
     * @param array{action?: string, actor_id?: int, limit?: int, offset?: int} $filters
     * @return list<AuditEntry>
     */
    public function listForSite(int $siteId, array $filters = []): array;

    /**
     * @param array{action?: string, actor_id?: int} $filters
     */
    public function countForSite(int $siteId, array $filters = []): int;
}
