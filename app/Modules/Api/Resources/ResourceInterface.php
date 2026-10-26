<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

/**
 * An API resource is an explicit projection of a domain object.
 *
 * The rule from the task pack is "never serialize a domain entity automatically".
 * A resource names every field it exposes, which means:
 *   - a new column on the entity never leaks into a response by accident;
 *   - a private field (a password hash) cannot appear because nobody wrote it;
 *   - the shape is reviewable in one place.
 */
interface ResourceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array;
}
