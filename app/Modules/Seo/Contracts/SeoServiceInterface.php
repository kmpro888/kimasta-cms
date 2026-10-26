<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Contracts;

use CMS\Modules\Seo\Entity\SeoMeta;

/**
 * Central SEO generation.
 *
 * The acceptance criterion is that a theme cannot produce conflicting canonical
 * logic. That is achieved by making this the only producer of SEO tags: a theme
 * receives rendered meta and prints it, it does not decide it.
 *
 * FROZEN CONTRACT.
 */
interface SeoServiceInterface
{
    /**
     * Build the SEO metadata for a content row, falling back to sensible
     * defaults derived from the content itself.
     */
    public function metaFor(int $contentId, int $siteId): SeoMeta;

    /**
     * Persist a custom SEO override for a content row.
     *
     * @param array<string, mixed> $attributes
     */
    public function store(int $contentId, int $siteId, array $attributes): SeoMeta;

    /**
     * Render the meta block as HTML for a template.
     */
    public function render(SeoMeta $meta): string;

    /**
     * Render the schema.org JSON-LD document for a piece of content.
     */
    public function renderSchema(SeoMeta $meta): string;

    /**
     * A content row that is excluded from search engines.
     */
    public function isIndexable(int $contentId, int $siteId): bool;
}
