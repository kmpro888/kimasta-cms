<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Service;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

/**
 * Generates the XML sitemap artifact.
 *
 * A crawler hitting `/sitemap.xml` must not trigger a content scan, so the file is
 * written ahead of time and invalidated by content events. `generate()` is the
 * only place that scans content.
 *
 * Only published, indexable content is included: a noindexed page in the sitemap
 * is a contradiction that search engines report as an error.
 */
final class SitemapService implements SitemapServiceInterface
{
    private const MAX_URLS = 50000;

    public function __construct(
        private readonly ContentQueryServiceInterface $query,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteServiceInterface $sites,
        private readonly SeoService $seo,
        private readonly string $directory,
    ) {
    }

    public function generate(int $siteId): int
    {
        $site = $this->sites->findById($siteId);
        $baseUrl = rtrim($site->baseUrl(), '/');

        $entries = [];
        $offset = 0;
        $pageSize = 200;

        // Paged so a large site does not load everything into memory at once.
        while (count($entries) < self::MAX_URLS) {
            $result = $this->query->paginate($siteId, [
                'published_only' => true,
                'exclude_trash' => true,
                'limit' => $pageSize,
                'offset' => $offset,
                'order' => 'newest',
            ]);

            $items = $result['items'];

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!$this->seo->isIndexable((int) $item->id, $siteId)) {
                    continue;
                }

                $entries[] = [
                    'loc' => $baseUrl . $this->urls->pathFor($item->type, $item->slug),
                    'lastmod' => $item->updatedAt->format(DATE_ATOM),
                ];
            }

            if (count($items) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        $xml = $this->renderXml($entries);

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return 0;
        }

        $path = $this->pathFor($siteId);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $xml) === false) {
            return 0;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $path)) {
            @unlink($temp);

            return 0;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return count($entries);
    }

    public function pathFor(int $siteId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'sitemap-' . $siteId . '.xml';
    }

    public function read(int $siteId): ?string
    {
        $path = $this->pathFor($siteId);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function invalidate(int $siteId): void
    {
        $path = $this->pathFor($siteId);

        if (is_file($path)) {
            @unlink($path);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * @param list<array{loc: string, lastmod: string}> $entries
     */
    private function renderXml(array $entries): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($entries as $entry) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            $lines[] = '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }
}
