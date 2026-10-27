<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Contracts;

/**
 * XML sitemap generation.
 *
 * The sitemap is a **generated artifact**, not something rebuilt on every request:
 * a crawler hitting `/sitemap.xml` must not trigger a full content scan. It is
 * written by the sitemap job and invalidated by content events.
 *
 * FROZEN CONTRACT.
 */
interface SitemapServiceInterface
{
    /**
     * Regenerate the sitemap file for a site.
     *
     * @return int number of URLs written
     */
    public function generate(int $siteId, ?int $maxUrls = null): int;

    /**
     * Path to the generated sitemap for a site (may not exist yet).
     */
    public function pathFor(int $siteId): string;

    /**
     * Path of one shard file.
     */
    public function pathForShard(int $siteId, int $shard): string;

    /**
     * The generated XML, or null when it has not been generated yet.
     */
    public function read(int $siteId): ?string;

    /**
     * One shard of a split sitemap, or null when that shard does not exist.
     */
    public function readShard(int $siteId, int $shard): ?string;

    /**
     * Drop the generated artifact so the next generate() rebuilds it.
     */
    public function invalidate(int $siteId): void;
}
