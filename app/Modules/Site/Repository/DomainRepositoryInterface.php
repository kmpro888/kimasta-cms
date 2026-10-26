<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Repository;

/**
 * Domain mapping persistence for the site_domains table.
 *
 * `domain` carries a global unique key, so one host can never point at two
 * tenants; the unique violation is surfaced as DuplicateDomainException by the
 * service layer rather than leaking a driver error.
 */
interface DomainRepositoryInterface
{
    /**
     * Site id mapped to a normalized domain, or null when unmapped.
     */
    public function findSiteIdByDomain(string $domain): ?int;

    /** @return list<string> */
    public function domainsForSite(int $siteId): array;

    public function primaryDomainForSite(int $siteId): ?string;

    /**
     * Map a domain onto a site. Marks it primary when asked, demoting any
     * previous primary for that site.
     */
    public function add(int $siteId, string $domain, bool $isPrimary): void;

    /**
     * Remove a domain mapping. Returns false when it was not mapped.
     */
    public function remove(int $siteId, string $domain): bool;

    /**
     * Every host -> site id mapping, used to compile the domain map.
     *
     * @return array<string, int>
     */
    public function allMappings(): array;
}
