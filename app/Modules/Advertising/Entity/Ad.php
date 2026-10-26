<?php

declare(strict_types=1);

namespace CMS\Modules\Advertising\Entity;

use DateTimeImmutable;

/**
 * A single ad creative.
 *
 * `bodyHtml` is raw HTML by design — an ad is a third-party snippet and sanitising it
 * would break most networks' tags. That makes it the most dangerous field in the
 * product, so it is gated behind `ads.manage`, which only an administrator holds.
 */
final class Ad
{
    /**
     * Placements the theme may ask for. Kept as constants so a typo in a template does
     * not silently produce an empty slot forever.
     */
    public const PLACEMENTS = [
        'header',
        'sidebar',
        'before_article',
        'middle_article',
        'after_article',
        'sticky',
        'footer',
    ];

    /**
     * @param array<string, list<string>> $rules rule type => allowed values
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly string $name,
        public readonly string $placement,
        public readonly string $bodyHtml,
        public readonly ?string $targetUrl,
        public readonly bool $isActive,
        public readonly int $priority,
        public readonly ?DateTimeImmutable $startsAt,
        public readonly ?DateTimeImmutable $endsAt,
        public readonly array $rules = [],
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public readonly DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }

    public static function isValidPlacement(string $placement): bool
    {
        return in_array($placement, self::PLACEMENTS, true);
    }

    /**
     * Is the ad inside its scheduled window?
     */
    public function isWithinWindow(DateTimeImmutable $now): bool
    {
        if ($this->startsAt !== null && $now < $this->startsAt) {
            return false;
        }

        if ($this->endsAt !== null && $now > $this->endsAt) {
            return false;
        }

        return true;
    }

    /**
     * Does every rule match the request context?
     *
     * Rules are AND-ed: an ad targeting "mobile AND category 5" runs only when both hold.
     * No rules means "no targeting" and therefore matches everything, which is the
     * behaviour an operator expects from an untargeted ad.
     *
     * @param array<string, mixed> $context
     */
    public function matchesRules(array $context): bool
    {
        foreach ($this->rules as $type => $allowed) {
            if (!$this->ruleMatches($type, $allowed, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $allowed
     * @param array<string, mixed> $context
     */
    private function ruleMatches(string $type, array $allowed, array $context): bool
    {
        return match ($type) {
            // A single-valued dimension: the context value must be among the allowed ones.
            'device', 'content_type' => in_array((string) ($context[$type] ?? ''), $allowed, true),

            // A multi-valued dimension: any overlap counts.
            'term' => $this->intersects($allowed, (array) ($context['term_ids'] ?? [])),

            // Unsupported rule types never match. Silently ignoring an unknown rule would
            // make an ad run in a place its author did not intend.
            default => false,
        };
    }

    /**
     * @param list<string> $allowed
     * @param array<int, mixed> $actual
     */
    private function intersects(array $allowed, array $actual): bool
    {
        foreach ($actual as $value) {
            if (in_array((string) $value, $allowed, true)) {
                return true;
            }
        }

        return false;
    }
}
