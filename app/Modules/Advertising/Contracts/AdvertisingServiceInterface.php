<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising\Contracts;

use CMS\Modules\Advertising\Entity\Ad;

/**
 * Advertising placements.
 *
 * The theme asks by placement name and never touches the database:
 *
 * ```php
 * $view->get('ads')['sidebar']
 * ```
 *
 * Targeting is evaluated in the service, not in the template, so a theme cannot
 * accidentally render an ad that was targeted away from the current page.
 *
 * FROZEN CONTRACT.
 */
interface AdvertisingServiceInterface
{
    /**
     * Ads that may run in this placement for this context, highest priority first.
     *
     * @param array{
     *     device?: string,
     *     content_type?: string,
     *     term_ids?: list<int>,
     *     path?: string
     * } $context
     *
     * @return list<Ad>
     */
    public function forPlacement(int $siteId, string $placement, array $context = []): array;

    /**
     * Rendered HTML for a placement, or an empty string when nothing is eligible.
     *
     * @param array<string, mixed> $context
     */
    public function render(int $siteId, string $placement, array $context = []): string;

    /**
     * Every placement rendered at once, for a page that shows several.
     *
     * One query per page rather than one per slot.
     *
     * @param list<string> $placements
     * @param array<string, mixed> $context
     *
     * @return array<string, string>
     */
    public function renderPlacements(int $siteId, array $placements, array $context = []): array;

    /**
     * @return list<Ad>
     */
    public function listForSite(int $siteId, ?string $placement = null): array;

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(int $siteId, array $attributes): Ad;

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $adId, int $siteId, array $attributes): Ad;

    public function delete(int $adId, int $siteId): bool;
}
