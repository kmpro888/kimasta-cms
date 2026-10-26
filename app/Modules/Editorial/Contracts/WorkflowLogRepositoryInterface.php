<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Contracts;

use CMS\Modules\Editorial\Entity\WorkflowLog;

/**
 * Workflow log persistence. Owns `workflow_logs` only.
 *
 * FROZEN CONTRACT.
 */
interface WorkflowLogRepositoryInterface
{
    public function record(
        int $siteId,
        int $contentId,
        int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $note,
    ): WorkflowLog;

    /**
     * @return list<WorkflowLog>
     */
    public function historyFor(int $contentId, int $siteId): array;

    public function countFor(int $contentId, int $siteId): int;
}
