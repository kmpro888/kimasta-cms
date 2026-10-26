<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

/**
 * Slug normalization and collision resolution.
 *
 * FROZEN CONTRACT.
 */
interface SlugServiceInterface
{
    /**
     * Normalize arbitrary text into a URL-safe slug.
     *
     * Lowercase, ASCII transliteration, non-alphanumerics collapsed to a single
     * hyphen, leading/trailing hyphens removed, truncated to the column limit
     * on a word boundary.
     */
    public function slugify(string $text): string;

    /**
     * Produce a slug that is unique for (site, type), appending -2, -3, …
     * when the base is taken.
     */
    public function uniqueFor(string $base, string $type, int $siteId, ?int $exceptId = null): string;

    /**
     * Is this slug available for the type on the site?
     */
    public function isAvailable(string $slug, string $type, int $siteId, ?int $exceptId = null): bool;
}
