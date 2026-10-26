<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising\Repository;

use CMS\Modules\Advertising\Entity\Ad;

/**
 * `ads` / `ad_rules` persistence.
 */
interface AdRepositoryInterface
{
    /**
     * All active ads for a site, with their rules attached.
     *
     * Loading every active ad in one query and matching in PHP avoids a join that grows
     * with the number of rules. A site has tens of ads, not thousands.
     *
     * @return list<Ad>
     */
    public function activeForSite(int $siteId): array;

    /**
     * @return list<Ad>
     */
    public function listForSite(int $siteId, ?string $placement = null): array;

    public function find(int $adId, int $siteId): ?Ad;

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, list<string>> $rules
     */
    public function create(int $siteId, array $attributes, array $rules): Ad;

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, list<string>>|null $rules null leaves the rules untouched
     */
    public function update(int $adId, int $siteId, array $attributes, ?array $rules): Ad;

    public function delete(int $adId, int $siteId): bool;
}
