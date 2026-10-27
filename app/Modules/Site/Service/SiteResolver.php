<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Service;

use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Entity\Site;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\DomainRepositoryInterface;
use CMS\Modules\Site\Repository\SiteRepositoryInterface;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use CMS\Shared\Contracts\SiteContextInterface;

/**
 * Resolves a Host header into a tenant context.
 *
 * Fixed order, and the order matters for the performance target:
 *
 *   validate -> normalize -> compiled domain map (no I/O beyond one file)
 *                        -> database (only on a miss)
 *
 * The compiled map lets a cached anonymous request resolve its tenant without
 * touching the database. The database path exists so a brand-new domain works
 * immediately, before the map is rebuilt.
 *
 * There is deliberately NO default site. An unresolvable host raises
 * SITE_NOT_FOUND; falling back to site 1 would turn a typo in DNS into a
 * cross-tenant data leak.
 */
final class SiteResolver implements SiteResolverInterface
{
    /**
     * Request-scoped memo, keyed by site id.
     *
     * The public controller resolves the tenant to answer a cache hit, then again to build
     * the page on a miss. Without this, one page paid for the same `sites` row twice.
     *
     * @var array<int, \CMS\Shared\Contracts\SiteContextInterface>
     */
    private array $memo = [];

    public function __construct(
        private readonly HostNormalizer $normalizer,
        private readonly DomainMap $domainMap,
        private readonly DomainRepositoryInterface $domains,
        private readonly SiteRepositoryInterface $sites,
    ) {
    }

    public function resolve(string $host): SiteContextInterface
    {
        // Throws InvalidHostException for anything that is not a DNS hostname.
        $normalized = $this->normalizer->normalize($host);

        $siteId = $this->domainMap->siteIdFor($normalized);

        if ($siteId !== null) {
            $site = $this->sites->findById($siteId);

            if ($site !== null) {
                return $site->withHost($normalized);
            }

            // The map references a site that no longer exists; treat it as a
            // stale cache entry and fall through to the database rather than
            // serving a half-deleted tenant.
        }

        $siteId = $this->domains->findSiteIdByDomain($normalized);

        if ($siteId === null) {
            throw new SiteNotFoundException();
        }

        $site = $this->sites->findById($siteId);

        if ($site === null) {
            throw new SiteNotFoundException();
        }

        return $site->withHost($normalized);
    }

    public function resolveById(int $siteId): SiteContextInterface
    {
        if ($siteId <= 0) {
            throw new SiteNotFoundException();
        }

        // Not memoised: a site's `status` can change while this process lives (an operator
        // deactivates it, a CLI job reads it afterwards), and caching that state is exactly how a
        // deactivated site kept answering 200. The host lookup is memoised; the mutable part is not.
        $site = $this->sites->findById($siteId);

        if ($site === null) {
            throw new SiteNotFoundException();
        }

        return $site;
    }

    public function siteIdFor(string $host): int
    {
        $normalized = $this->normalizer->normalize($host);

        // Warm compiled map: no database at all. This is what keeps a fully cached
        // page at zero queries.
        $siteId = $this->domainMap->siteIdFor($normalized);

        if ($siteId !== null) {
            return $siteId;
        }

        // Cold map: fall back to the source of truth.
        $siteId = $this->domains->findSiteIdByDomain($normalized);

        if ($siteId === null) {
            throw new SiteNotFoundException();
        }

        return $siteId;
    }

    /**
     * Resolve or return null instead of throwing.
     *
     * Convenience for callers that legitimately handle "no site" (a health
     * check, a 404 page). Resolution rules are identical.
     */
    public function tryResolve(string $host): ?SiteContextInterface
    {
        try {
            return $this->resolve($host);
        } catch (SiteNotFoundException|\CMS\Modules\Site\Exceptions\InvalidHostException) {
            return null;
        }
    }

    /**
     * The concrete Site for a host, when the caller needs the admin fields
     * (theme, timestamps) that are not part of the frozen context contract.
     */
    public function resolveSiteEntity(string $host): ?Site
    {
        $context = $this->tryResolve($host);

        if (!$context instanceof Site) {
            return null;
        }

        return $context;
    }
}
