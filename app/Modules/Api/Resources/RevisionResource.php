<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Revision\Entity\Revision;

/**
 * Revision projection.
 *
 * The compiled `content_html` is omitted: a revision is restored through the
 * service, and exposing stale compiled markup invites it being trusted.
 */
final class RevisionResource implements ResourceInterface
{
    /**
     * @param Revision $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof Revision) {
            throw new \InvalidArgumentException('RevisionResource expects a Revision entity.');
        }

        return [
            'id' => $subject->id,
            'content_id' => $subject->contentId,
            'revision_number' => $subject->revisionNumber,
            'title' => $subject->title,
            'excerpt' => $subject->excerpt,
            'blocks' => $subject->contentJson === null ? null : json_decode($subject->contentJson, true),
            'created_by' => $subject->createdBy,
            'created_at' => $subject->createdAt->format(DATE_ATOM),
        ];
    }
}
