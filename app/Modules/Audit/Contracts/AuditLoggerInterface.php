<?php

declare(strict_types=1);

namespace CMS\Modules\Audit\Contracts;

use CMS\Modules\Audit\Entity\AuditEntry;
use CMS\Shared\Contracts\ActorInterface;

/**
 * Records that something privileged happened.
 *
 * Recording is **best effort**: a failure to write the log must never fail the action that was
 * logged. An operator who changed a setting and got an error because the audit table was full would
 * rightly consider the log the problem.
 */
interface AuditLoggerInterface
{
    /**
     * @param array<string, scalar|list<string>|null> $context
     */
    public function record(
        int $siteId,
        ?ActorInterface $actor,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = [],
        ?string $ip = null,
    ): void;

    /**
     * @param array{action?: string, actor_id?: int, limit?: int, offset?: int} $filters
     * @return list<AuditEntry>
     */
    public function entries(int $siteId, array $filters = []): array;

    /**
     * @param array{action?: string, actor_id?: int} $filters
     */
    public function count(int $siteId, array $filters = []): int;
}
