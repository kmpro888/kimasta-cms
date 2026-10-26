<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;

/**
 * Explicit content projection.
 *
 * `content_json` is exposed; the compiled `content_html` is not, because a client
 * that wants markup should ask the public page, and shipping compiled HTML through
 * the API invites it being trusted as sanitized.
 *
 * When a URL generator is supplied, `path` is included so a client does not have to
 * reimplement permalink rules.
 */
final class ContentResource implements ResourceInterface
{
    public function __construct(private readonly ?UrlGeneratorInterface $urls = null)
    {
    }

    /**
     * @param Content $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof Content) {
            throw new \InvalidArgumentException('ContentResource expects a Content entity.');
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
            'comment_status' => $subject->commentStatus,
            'blocks' => $subject->blocks(),
            'published_at' => $subject->publishedAt?->format(DATE_ATOM),
            'scheduled_at' => $subject->scheduledAt?->format(DATE_ATOM),
            'created_at' => $subject->createdAt->format(DATE_ATOM),
            'updated_at' => $subject->updatedAt->format(DATE_ATOM),
        ];

        if ($this->urls !== null) {
            $payload['path'] = $this->urls->pathFor($subject->type, $subject->slug);
        }

        return $payload;
    }
}
