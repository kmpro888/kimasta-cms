<?php

declare(strict_types=1);

namespace CMS\Modules\Cache\Entity;

use CMS\Modules\Cache\Contracts\CacheDependencyInterface;

/**
 * One cache dependency, e.g. `content:42`.
 *
 * The key is stable and derived only from type+id, so two call sites that depend
 * on the same entity always produce the same key.
 */
final class CacheDependency implements CacheDependencyInterface
{
    public function __construct(
        private readonly string $dependencyType,
        private readonly string $dependencyId,
    ) {
        if ($dependencyType === '' || $dependencyId === '') {
            throw new \InvalidArgumentException('A cache dependency needs both a type and an id.');
        }
    }

    public function key(): string
    {
        return $this->dependencyType . ':' . $this->dependencyId;
    }

    public function type(): string
    {
        return $this->dependencyType;
    }

    public function id(): string
    {
        return $this->dependencyId;
    }

    public static function content(int $id): self
    {
        return new self('content', (string) $id);
    }

    public static function term(int $id): self
    {
        return new self('term', (string) $id);
    }

    public static function author(int $id): self
    {
        return new self('author', (string) $id);
    }

    public static function media(int $id): self
    {
        return new self('media', (string) $id);
    }

    public static function theme(string $name): self
    {
        return new self('theme', $name);
    }

    public static function settings(int $siteId): self
    {
        return new self('settings', (string) $siteId);
    }

    /**
     * Any page whose body is a list of posts (home, category, tag, author, search).
     *
     * Declaring this is what lets a single article change refresh the listings
     * without wiping static pages that declared nothing.
     */
    public static function archive(int $siteId): self
    {
        return new self('archive', (string) $siteId);
    }
}
