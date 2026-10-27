<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Contracts;

use CMS\Shared\Contracts\SiteContextInterface;

/**
 * Read/write access to the three multisite tables owned by this module.
 *
 * All lookups are index-backed and use prepared statements with explicit
 * column lists. Mutating a domain mapping must regenerate the compiled
 * domain map (the implementation is responsible for doing so).
 */
interface SiteServiceInterface
{
    /**
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function findById(int $siteId): SiteContextInterface;

    /**
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function findBySlug(string $slug): SiteContextInterface;

    /**
     * @return list<SiteContextInterface>
     */
    public function all(): array;

    /**
     * Map an additional domain onto a site and regenerate the domain map.
     *
     * @throws \CMS\Modules\Site\Exceptions\DuplicateDomainException
     * @throws \CMS\Modules\Site\Exceptions\InvalidHostException
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    /**
     * Update the editable fields: name, default_locale, timezone, base_url.
     *
     * @param array<string, mixed> $attributes
     */
    /**
     * Create a site and map its primary domain.
     *
     * The service owns this because creating a site is never just an insert: the domain row and the
     * compiled domain map have to move with it, or the new tenant is unreachable.
     */
    public function create(
        string $name,
        string $slug,
        string $primaryDomain,
        string $defaultLocale = 'en',
        string $timezone = 'UTC',
        string $baseUrl = '',
        string $status = 'active',
    ): SiteContextInterface;
    public function update(int $siteId, array $attributes): SiteContextInterface;

    /**
     * Domains mapped onto a site, primary first.
     *
     * @return list<string>
     */
    public function domains(int $siteId): array;
    public function addDomain(int $siteId, string $domain, bool $isPrimary = false): void;

    /**
     * Remove a mapped domain and regenerate the domain map.
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function removeDomain(int $siteId, string $domain): void;

    /**
     * Recompile /storage/cache/system/domain-map.php from the database.
     *
     * @return int Number of host mappings written.
     */
    public function regenerateDomainMap(): int;
}