<?php

declare(strict_types=1);

namespace CMS\Modules\Notification\Entity;

use DateTimeImmutable;

/**
 * An in-app notification for one recipient.
 */
final class Notification
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly int $userId,
        public readonly string $event,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int $contentId,
        public readonly ?DateTimeImmutable $readAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            siteId: (int) $row['site_id'],
            userId: (int) $row['user_id'],
            event: (string) $row['event'],
            title: (string) $row['title'],
            body: (string) $row['body'],
            contentId: isset($row['content_id']) ? (int) $row['content_id'] : null,
            readAt: isset($row['read_at']) && $row['read_at'] !== null
                ? new DateTimeImmutable((string) $row['read_at'])
                : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
