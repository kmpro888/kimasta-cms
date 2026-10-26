<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Entity;

/**
 * A cached full page.
 *
 * `html` is what gets sent; `dependencies` and `storedAt` exist so the entry can be
 * reasoned about and evicted correctly without a database lookup.
 */
final class CachedPage
{
    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        public readonly string $html,
        public readonly array $dependencies,
        public readonly int $storedAt,
        public readonly ?int $expiresAt,
    ) {
    }

    public function isExpired(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? time()) >= $this->expiresAt;
    }
}
