<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Service;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Content\Repository\UrlRegistryRepositoryInterface;

/**
 * Read side for public pages.
 *
 * An uncached single-content request costs two logical queries: one indexed
 * lookup in `url_registry` and one primary-key fetch of the row. That stays
 * inside the task pack's "≤ 5 queries before optional relations" target, and it
 * is why the URL registry exists at all — the alternative is guessing across
 * several tables.
 *
 * Listings go through `paginate()`, which cannot select the block payload.
 */
final class ContentQueryService implements ContentQueryServiceInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ContentRepositoryInterface $contents,
        private readonly UrlRegistryRepositoryInterface $registry,
    ) {
    }

    public function paginate(int $siteId, array $criteria = []): array
    {
        $perPage = (int) ($criteria['limit'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $page = (int) ($criteria['page'] ?? 1);
        $page = max(1, $page);

        $offset = (int) ($criteria['offset'] ?? (($page - 1) * $perPage));

        unset($criteria['limit'], $criteria['offset'], $criteria['page']);

        $items = $this->contents->paginate($siteId, $criteria, $perPage, $offset);
        $total = $this->contents->count($siteId, $criteria);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    public function published(int $siteId, string $type = 'post', int $limit = 20, int $offset = 0): array
    {
        return $this->contents->paginate(
            $siteId,
            ['type' => $type, 'published_only' => true, 'order' => 'newest'],
            $limit,
            $offset
        );
    }

    public function findByUrl(int $siteId, string $path): Content
    {
        $normalized = $this->normalizePath($path);

        $mapping = $this->registry->resolve($siteId, $normalized);

        if ($mapping === null || $mapping['entity_type'] !== 'content') {
            throw new ContentNotFoundException();
        }

        $content = $this->contents->findById($mapping['entity_id'], $siteId);

        if ($content === null) {
            // A registry row pointing at a missing content row is stale state;
            // treat it as not found rather than serving a broken page.
            throw new ContentNotFoundException();
        }

        return $content;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
