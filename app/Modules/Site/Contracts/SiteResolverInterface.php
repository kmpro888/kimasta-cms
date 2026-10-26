<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Contracts;

use CMS\Shared\Contracts\SiteContextInterface;

/**
 * Resolves a raw request Host header into a tenant SiteContext.
 *
 * Resolution order is fixed: validate -> normalize -> filesystem domain map
 * -> database fallback. There is never a silent fallback to site 1: an
 * unknown or malformed host raises SiteNotFoundException (SITE_NOT_FOUND).
 */
interface SiteResolverInterface
{
    /**
     * @throws \CMS\Modules\Site\Exceptions\InvalidHostException
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function resolve(string $host): SiteContextInterface;

    /**
     * Resolve the context for an already-known site id.
     *
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function resolveById(int $siteId): SiteContextInterface;

    /**
     * Resolve only the site id for a host.
     *
     * This exists for the cache-first fast path: on a warm compiled domain map it
     * answers with **zero database queries**, so a fully cached page never needs a
     * site row. Build the full context with resolveById() only on a cache miss.
     *
     * @throws \CMS\Modules\Site\Exceptions\InvalidHostException
     * @throws \CMS\Modules\Site\Exceptions\SiteNotFoundException
     */
    public function siteIdFor(string $host): int;
}