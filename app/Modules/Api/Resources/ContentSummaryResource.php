<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Content\Entity\ContentSummary;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;

/**
 * Listing projection of content.
 *
 * A separate resource from `ContentResource` on purpose: a listing must not carry
 * the block payload, and having two classes makes that structural instead of a
 * convention someone can forget.
 */
final class ContentSummaryResource implements ResourceInterface
{
    public function __construct(private readonly ?UrlGeneratorInterface $urls = null)
    {
    }

    /**
     * @param ContentSummary $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof ContentSummary) {
            throw new \InvalidArgumentException('ContentSummaryResource expects a ContentSummary.');
        }

        $payload = [
            'id' => $subject->id,
            'type' => $subject->type,
            'title' => $subject->title,
            'slug' => $subject->slug,
            'excerpt' => $subject->excerpt,
            'status' => $subject->status,
            'author_id' => $subject->authorId,
            'featured_media_id' => $subject->featuredMediaId,
            'published_at' => $subject->publishedAt?->format(DATE_ATOM),
            'updated_at' => $subject->updatedAt->format(DATE_ATOM),
        ];

        if ($this->urls !== null) {
            $payload['path'] = $this->urls->pathFor($subject->type, $subject->slug);
        }

        return $payload;
    }
}
