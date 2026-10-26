<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Editorial\Entity\WorkflowLog;
use CMS\Modules\Revision\Entity\Revision;

/**
 * Workflow history projection.
 */
final class WorkflowLogResource implements ResourceInterface
{
    /**
     * @param WorkflowLog $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof WorkflowLog) {
            throw new \InvalidArgumentException('WorkflowLogResource expects a WorkflowLog entity.');
        }

        return [
            'id' => $subject->id,
            'content_id' => $subject->contentId,
            'user_id' => $subject->userId,
            'from_status' => $subject->fromStatus,
            'to_status' => $subject->toStatus,
            'note' => $subject->note,
            'created_at' => $subject->createdAt->format(DATE_ATOM),
        ];
    }
}
