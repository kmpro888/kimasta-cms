<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Entity;

use DateTimeImmutable;

/**
 * One recorded editorial transition.
 *
 * `userId` is 0 for a system transition (the scheduler publishing due content),
 * because the audit trail must record that something happened even when no human
 * caused it.
 */
final class WorkflowLog
{
    public const SYSTEM_USER = 0;

    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly int $contentId,
        public readonly int $userId,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly ?string $note,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isSystem(): bool
    {
        return $this->userId === self::SYSTEM_USER;
    }
}
