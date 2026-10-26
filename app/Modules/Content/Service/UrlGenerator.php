<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Service;

use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Exceptions\UrlCollisionException;
use CMS\Modules\Content\Repository\UrlRegistryRepositoryInterface;

/**
 * Canonical path generation plus URL registry maintenance.
 *
 * The path shape is kept intentionally simple and centralised here: when a
 * later agent adds permalink patterns (dates, categories), only this class
 * changes.
 */
final class UrlGenerator implements UrlGeneratorInterface
{
    /** Content types that live at the site root instead of under a prefix. */
    private const ROOT_TYPES = ['page'];

    public function __construct(private readonly UrlRegistryRepositoryInterface $registry)
    {
    }

    public function pathFor(string $type, string $slug): string
    {
        $slug = trim($slug, '/');

        if (in_array($type, self::ROOT_TYPES, true)) {
            return '/' . $slug;
        }

        return '/' . $this->prefixFor($type) . '/' . $slug;
    }

    public function register(int $siteId, string $path, string $entityType, int $entityId): void
    {
        $path = $this->normalizePath($path);

        if ($this->registry->isTakenByAnother($siteId, $path, $entityType, $entityId)) {
            throw new UrlCollisionException();
        }

        $this->registry->put($siteId, $path, $entityType, $entityId);
    }

    public function forget(int $siteId, string $entityType, int $entityId): void
    {
        $this->registry->forget($siteId, $entityType, $entityId);
    }

    public function resolve(int $siteId, string $path): ?array
    {
        return $this->registry->resolve($siteId, $this->normalizePath($path));
    }

    public function isPathTaken(int $siteId, string $path, string $entityType, int $entityId): bool
    {
        return $this->registry->isTakenByAnother(
            $siteId,
            $this->normalizePath($path),
            $entityType,
            $entityId
        );
    }

    /**
     * Path prefix for a content type. Custom types are pluralised, which keeps
     * a registered type immediately usable without a code change.
     */
    private function prefixFor(string $type): string
    {
        return match ($type) {
            'post' => 'blog',
            default => $type . 's',
        };
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        // Strip query/fragment, collapse duplicate slashes, ensure a single
        // leading slash and no trailing slash (except root).
        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
