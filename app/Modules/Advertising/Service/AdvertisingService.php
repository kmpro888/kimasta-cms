<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising\Service;

use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Advertising\Entity\Ad;
use CMS\Modules\Advertising\Repository\AdRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Ad selection and rendering.
 *
 * Selection is deliberate about three questions, each of which has a wrong answer that
 * looks reasonable:
 *
 *  1. **What is eligible?** Active, inside its date window, and matching every targeting
 *     rule. A rule type the service does not understand makes the ad ineligible rather
 *     than being ignored — an ad must never run somewhere its author did not intend.
 *  2. **Which one wins?** Highest priority first, then oldest. Returning the whole list
 *     lets a template stack ads in a slot; returning one would need a tie-break policy
 *     the operator cannot see.
 *  3. **Is the HTML escaped?** No. An ad is a third-party snippet; sanitising it breaks
 *     most networks' tags. That makes `ads.manage` an administrator-only capability —
 *     the security boundary is who may store an ad, not what the renderer does with it.
 */
final class AdvertisingService implements AdvertisingServiceInterface
{
    public function __construct(
        private readonly AdRepositoryInterface $ads,
        private readonly ClockInterface $clock,
    ) {
    }

    public function forPlacement(int $siteId, string $placement, array $context = []): array
    {
        if (!Ad::isValidPlacement($placement)) {
            // A typo in a template must fail visibly in review, not silently render
            // nothing forever.
            throw new \InvalidArgumentException('Unknown ad placement: ' . $placement);
        }

        $now = $this->clock->now();

        $eligible = [];

        foreach ($this->ads->activeForSite($siteId) as $ad) {
            if ($ad->placement !== $placement) {
                continue;
            }

            if (!$ad->isWithinWindow($now)) {
                continue;
            }

            if (!$ad->matchesRules($context)) {
                continue;
            }

            $eligible[] = $ad;
        }

        return $eligible;
    }

    public function render(int $siteId, string $placement, array $context = []): string
    {
        return $this->renderPlacements($siteId, [$placement], $context)[$placement] ?? '';
    }

    public function renderPlacements(int $siteId, array $placements, array $context = []): array
    {
        $out = [];

        foreach ($placements as $placement) {
            $out[$placement] = '';
        }

        if ($placements === []) {
            return $out;
        }

        // One read of the active ad set for every slot on the page.
        $now = $this->clock->now();
        $active = $this->ads->activeForSite($siteId);

        foreach ($placements as $placement) {
            if (!Ad::isValidPlacement($placement)) {
                continue;
            }

            $html = [];

            foreach ($active as $ad) {
                if ($ad->placement !== $placement
                    || !$ad->isWithinWindow($now)
                    || !$ad->matchesRules($context)
                ) {
                    continue;
                }

                $html[] = $this->renderAd($ad);
            }

            $out[$placement] = implode("\n", $html);
        }

        return $out;
    }

    public function listForSite(int $siteId, ?string $placement = null): array
    {
        return $this->ads->listForSite($siteId, $placement);
    }

    public function create(int $siteId, array $attributes): Ad
    {
        $this->assertPlacement($attributes['placement'] ?? null);

        return $this->ads->create($siteId, $attributes, $this->normaliseRules($attributes['rules'] ?? []));
    }

    public function update(int $adId, int $siteId, array $attributes): Ad
    {
        if (array_key_exists('placement', $attributes)) {
            $this->assertPlacement($attributes['placement']);
        }

        $rules = array_key_exists('rules', $attributes)
            ? $this->normaliseRules($attributes['rules'])
            : null;

        return $this->ads->update($adId, $siteId, $attributes, $rules);
    }

    public function delete(int $adId, int $siteId): bool
    {
        return $this->ads->delete($adId, $siteId);
    }

    /**
     * Wrap the creative and mark it so a template can style it and a reader's tooling can
     * recognise an advertisement.
     */
    private function renderAd(Ad $ad): string
    {
        $wrapper = '<div class="ad" data-ad="' . htmlspecialchars((string) $ad->id, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-placement="' . htmlspecialchars($ad->placement, ENT_QUOTES, 'UTF-8') . '">';

        $body = $ad->bodyHtml;

        if ($ad->targetUrl !== null && $ad->targetUrl !== '') {
            $body = '<a href="' . htmlspecialchars($ad->targetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' rel="nofollow sponsored noopener" target="_blank">' . $body . '</a>';
        }

        return $wrapper . $body . '</div>';
    }

    private function assertPlacement(mixed $placement): void
    {
        if (!is_string($placement) || !Ad::isValidPlacement($placement)) {
            throw new \InvalidArgumentException(
                'Unknown ad placement. Allowed: ' . implode(', ', Ad::PLACEMENTS) . '.'
            );
        }
    }

    /**
     * Normalise targeting rules to `type => list<value>`.
     *
     * Only known dimensions are accepted; an unknown one is refused rather than stored,
     * because a stored rule that can never match is a silent outage for that ad.
     *
     * @return array<string, list<string>>
     */
    private function normaliseRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $allowed = ['device', 'content_type', 'term'];
        $out = [];

        foreach ($rules as $type => $values) {
            if (!is_string($type) || !in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported ad rule type: ' . (string) $type);
            }

            $list = is_array($values) ? $values : [$values];
            $cleaned = [];

            foreach ($list as $value) {
                if (is_string($value) || is_int($value)) {
                    $cleaned[] = (string) $value;
                }
            }

            if ($cleaned !== []) {
                $out[$type] = array_values(array_unique($cleaned));
            }
        }

        return $out;
    }
}
