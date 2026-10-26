<?php

declare(strict_types=1);

namespace CMS\Modules\Comment\Entity;

use DateTimeImmutable;

/**
 * A single comment.
 */
final class Comment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SPAM = 'spam';
    public const STATUS_TRASH = 'trash';

    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly int $contentId,
        public readonly ?int $parentId,
        public readonly int $userId,
        public readonly string $authorName,
        public readonly string $body,
        public readonly string $status,
        public readonly int $depth,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            siteId: (int) $row['site_id'],
            contentId: (int) $row['content_id'],
            parentId: isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            userId: (int) $row['user_id'],
            authorName: (string) $row['author_name'],
            body: (string) $row['body'],
            status: (string) $row['status'],
            depth: (int) ($row['depth'] ?? 0),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
