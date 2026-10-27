<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Service;

use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Site\Exceptions\DuplicateDomainException;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Site\Repository\DomainRepositoryInterface;
use CMS\Modules\Site\Repository\SiteRepositoryInterface;
use CMS\Modules\Site\Support\DomainMap;
use CMS\Modules\Site\Support\HostNormalizer;
use CMS\Shared\Contracts\SiteContextInterface;
use PDOException;

/**
 * Administrative access to the multisite tables.
 *
 * The single most important rule in this class: ANY mutation that changes host
 * mapping regenerates the compiled domain map before returning. That is what
 * makes a newly added custom domain resolve immediately, and what keeps the
 * cache from going stale behind an admin action.
 */
final class SiteService implements SiteServiceInterface
{
    /**
     * Request-scoped memo for `findById()`.
     *
     * A single page resolves its tenant six times: the controller, the SEO service, the
     * breadcrumbs, the view-model factory, the ad service and settings. Each of those cost
     * a full `SELECT` on `sites`. Cleared by every mutating method below, so it can never
     * serve a site row the current request has just changed.
     *
     * @var array<int, SiteContextInterface>
     */
    private array $memo = [];

    public function __construct(
        private readonly SiteRepositoryInterface $sites,
        private readonly DomainRepositoryInterface $domains,
        private readonly DomainMap $domainMap,
        private readonly HostNormalizer $normalizer,
    ) {
    }

    public function findById(int $siteId): SiteContextInterface
    {
        if (isset($this->memo[$siteId])) {
            return $this->memo[$siteId];
        }

        $site = $siteId > 0 ? $this->sites->findById($siteId) : null;

        if ($site === null) {
            throw new SiteNotFoundException();
        }

        return $this->memo[$siteId] = $site;
    }

    public function findBySlug(string $slug): SiteContextInterface
    {
        $site = $this->sites->findBySlug($slug);

        if ($site === null) {
            throw new SiteNotFoundException();
        }

        return $site;
    }

    public function all(): array
    {
        return $this->sites->all();
    }

    /**
     * Update the editable fields of a site.
     *
     * The memo is cleared and the row re-read, so a caller that changed the site and then asks for
     * it does not receive the version from before the write.
     *
     * The domain is deliberately not editable here: moving it has to move the domain mapping and
     * regenerate the compiled map, and the old domain stops resolving at once, which is not a
     * change to offer behind a text field.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(int $siteId, array $attributes): SiteContextInterface
    {
        $this->sites->update($siteId, $attributes);
        $this->memo = [];

        return $this->findById($siteId);
    }
    /**
     * @return list<string>
     */
    public function domains(int $siteId): array
    {
        return $this->domains->domainsForSite($siteId);
    }
    public function addDomain(int $siteId, string $domain, bool $isPrimary = false): void
    {
        $this->memo = [];
        if ($this->sites->findById($siteId) === null) {
            throw new SiteNotFoundException();
        }

        $normalized = $this->normalizer->normalize($domain);

        // Pre-check for a clean 409. The unique key below is the real
        // guarantee under concurrency; this just avoids a driver error in the
        // common case.
        $existing = $this->domains->findSiteIdByDomain($normalized);

        if ($existing !== null) {
            throw new DuplicateDomainException();
        }

        try {
            $this->domains->add($siteId, $normalized, $isPrimary);
        } catch (PDOException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new DuplicateDomainException(previous: $exception);
            }

            throw $exception;
        }

        $this->regenerateDomainMap();
    }

    public function removeDomain(int $siteId, string $domain): void
    {
        $this->memo = [];
        if ($this->sites->findById($siteId) === null) {
            throw new SiteNotFoundException();
        }

        $normalized = $this->normalizer->normalize($domain);

        $this->domains->remove($siteId, $normalized);
        $this->regenerateDomainMap();
    }

    public function regenerateDomainMap(): int
    {
        return $this->domainMap->compile($this->domains->allMappings());
    }

    /**
     * Create a site and register its primary domain as a mapping row, then
     * rebuild the map so the new tenant resolves immediately.
     */
    public function create(
        string $name,
        string $slug,
        string $primaryDomain,
        string $defaultLocale = 'en',
        string $timezone = 'UTC',
        string $baseUrl = '',
        string $status = 'active',
    ): SiteContextInterface {
        $normalized = $this->normalizer->normalize($primaryDomain);

        // Checked before the insert, not after: creating the site first and failing on the domain
        // row leaves a tenant with no way to be reached — an orphan the operator cannot see or
        // delete. The check is not a substitute for the unique index, it is what keeps the failure
        // from leaving debris behind.
        if ($this->domains->findSiteIdByDomain($normalized) !== null) {
            throw new DuplicateDomainException($normalized . ' is already mapped to a site.');
        }

        $site = $this->sites->create(
            name: $name,
            slug: $slug,
            primaryDomain: $normalized,
            defaultLocale: $defaultLocale,
            timezone: $timezone,
            baseUrl: $baseUrl,
            status: $status,
        );

        if ($site->id() <= 0) {
            throw new SiteNotFoundException();
        }

        $this->domains->add($site->id(), $normalized, true);
        $this->regenerateDomainMap();

        return $site;
    }

    /**
     * Normalize a host without resolving it, for callers that only need the
     * canonical form (admin forms, validation messages).
     *
     * @throws InvalidHostException
     */
    public function normalizeHost(string $host): string
    {
        return $this->normalizer->normalize($host);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        // SQLSTATE 23000 covers integrity constraint violations; MySQL driver
        // code 1062 is specifically a duplicate entry.
        return $exception->getCode() === '23000'
            || (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
