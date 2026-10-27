<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Repository;

use CMS\Modules\Site\Entity\Site;

/**
 * Site row persistence.
 *
 * Every query uses an explicit column list and a prepared statement. There is
 * no ambient "current site" here: callers always pass an explicit id or slug.
 */
interface SiteRepositoryInterface
{
    public function findById(int $siteId): ?Site;

    public function findBySlug(string $slug): ?Site;

    /**
     * Resolve a site by any mapped domain, including its primary domain.
     */
    public function findByDomain(string $domain): ?Site;

    /** @return list<Site> */
    public function all(): array;

    public function create(
        string $name,
        string $slug,
        string $primaryDomain,
        string $defaultLocale,
        string $timezone,
        string $baseUrl,
        string $status,
    ): Site;

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $siteId, array $attributes): void;

    public function updateStatus(int $siteId, string $status): void;

    public function updateTheme(int $siteId, ?string $theme): void;

    public function updatePrimaryDomain(int $siteId, string $domain): void;
}
