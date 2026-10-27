<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Repository;

/**
 * `redirects` persistence.
 *
 * Exact lookup only: a redirect miss happens on every 404, so it must be one
 * indexed hit and never a scan.
 */
interface RedirectRepositoryInterface
{
    /**
     * @return array{target_url: string, status_code: int}|null
     */
    public function find(int $siteId, string $sourcePath): ?array;

    public function put(int $siteId, string $sourcePath, string $targetUrl, int $statusCode): void;

    public function delete(int $siteId, string $sourcePath): bool;

    /**
     * @return list<array{source_path: string, target_url: string, status_code: int}>
     */
    public function incrementHits(int $siteId, string $sourcePath, int $by): void;

    public function listForSite(int $siteId): array;
}
