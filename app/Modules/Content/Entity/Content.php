<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Entity;

use DateTimeImmutable;

/**
 * Immutable content row.
 *
 * `content_json` is the canonical representation; `content_html` and
 * `content_plain` are derived at save time so a public page never compiles
 * blocks during a view.
 */
final class Content
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REVIEW = 'review';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_TRASH = 'trash';

    /**
     * Canonical status list. The database intentionally does not use ENUM so a
     * plugin or workflow migration can extend the vocabulary; this list is the
     * application-layer validation source.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_REVIEW,
            self::STATUS_REVISION_REQUESTED,
            self::STATUS_APPROVED,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
            self::STATUS_TRASH,
        ];
    }

    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly string $type,
        public readonly int $authorId,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?string $contentJson,
        public readonly ?string $contentHtml,
        public readonly ?string $contentPlain,
        public readonly string $status,
        public readonly ?int $featuredMediaId,
        public readonly string $commentStatus,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly ?DateTimeImmutable $scheduledAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Decoded block JSON.
     *
     * @return array<int, array<string, mixed>>
     */
    public function blocks(): array
    {
        if ($this->contentJson === null || $this->contentJson === '') {
            return [];
        }

        $decoded = json_decode($this->contentJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isTrashed(): bool
    {
        return $this->status === self::STATUS_TRASH;
    }

    /**
     * Whether the row is visible to an anonymous visitor right now.
     */
    public function isPubliclyVisible(?DateTimeImmutable $now = null): bool
    {
        if (!$this->isPublished()) {
            return false;
        }

        if ($this->publishedAt === null) {
            return true;
        }

        $now ??= new DateTimeImmutable('now');

        return $this->publishedAt <= $now;
    }
}
