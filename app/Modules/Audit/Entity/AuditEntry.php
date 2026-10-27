<?php

declare(strict_types=1);

namespace CMS\Modules\Audit\Entity;

use DateTimeImmutable;

/**
 * One recorded action.
 *
 * A value object rather than a row: the screen reads it, nothing mutates it, and the snapshot fields
 * (`actorName`) are part of the record, not a lookup.
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly ?int $actorId,
        public readonly string $actorName,
        public readonly string $action,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly array $context,
        public readonly ?string $ip,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
