<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Taxonomy\Entity\Term;

/**
 * Explicit term projection.
 */
final class TermResource implements ResourceInterface
{
    /**
     * @param Term $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof Term) {
            throw new \InvalidArgumentException('TermResource expects a Term entity.');
        }

        return [
            'id' => $subject->id,
            'taxonomy_id' => $subject->taxonomyId,
            'parent_id' => $subject->parentId,
            'name' => $subject->name,
            'slug' => $subject->slug,
            'description' => $subject->description,
            'content_count' => $subject->contentCount,
        ];
    }
}
