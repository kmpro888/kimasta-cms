<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Contracts;

/**
 * A declared dependency of a cache entry.
 *
 * Example: a page `/blog/hello` depends on `content:42`, `term:8`, `author:3`,
 * `theme:default` and `settings`. Invalidation is then targeted: change one
 * article and only the entries that declared it are evicted.
 *
 * FROZEN CONTRACT.
 */
interface CacheDependencyInterface
{
    /**
     * Stable identifier, e.g. `content:42` or `theme:default`.
     */
    public function key(): string;

    public function type(): string;

    public function id(): string;
}
