<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Contracts;

/**
 * Redirect management.
 *
 * Lookup is an exact indexed hit on (site_id, source_path): no regex, no table
 * scan. That is what keeps a redirect cheap on every miss request.
 *
 * FROZEN CONTRACT.
 */
interface RedirectServiceInterface
{
    /**
     * Resolve a source path to a redirect target, or null.
     *
     * @return array{target_url: string, status_code: int}|null
     */
    public function resolve(int $siteId, string $path): ?array;

    /**
     * Create or replace a redirect.
     *
     * @throws \CMS\Modules\Seo\Exceptions\RedirectLoopException
     * @throws \CMS\Modules\Seo\Exceptions\InvalidRedirectException
     */
    public function store(int $siteId, string $sourcePath, string $targetUrl, int $statusCode = 301): void;

    public function delete(int $siteId, string $sourcePath): bool;

    /**
     * @return list<array{source_path: string, target_url: string, status_code: int}>
     */
    public function listForSite(int $siteId): array;
}
