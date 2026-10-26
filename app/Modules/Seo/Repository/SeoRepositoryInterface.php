<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Repository;

/**
 * `content_seo` persistence.
 *
 * Row shape mirrors the schema exactly; there is no generic meta table, so reading
 * SEO for a page is one indexed lookup, not a join through key/value pairs.
 */
interface SeoRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(int $contentId, int $siteId): ?array;

    /**
     * @param array<string, mixed> $attributes
     */
    public function put(int $contentId, int $siteId, array $attributes): void;

    public function delete(int $contentId, int $siteId): void;
}
