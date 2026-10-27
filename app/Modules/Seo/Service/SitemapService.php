<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Service;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

/**
 * Builds each site's XML sitemap as a file, so serving it is a read.
 *
 * A sitemap is capped by the protocol at 50,000 URLs, and this writes one file per 50,000 and an
 * index above them. Below that cap the site gets a single `<urlset>` at the usual path, which is
 * what search engines expect from a small site; above it, `/sitemap.xml` becomes a
 * `<sitemapindex>` pointing at `/sitemap-<n>.xml`.
 *
 * The sharding exists because the previous version simply stopped at 50,000 URLs: a larger site
 * silently lost every article past the cap, which is a contradiction the search engines report as
 * an error rather than a warning.
 */
final class SitemapService implements SitemapServiceInterface
{
    /** The protocol limit per file. */
    private const MAX_URLS = 50000;

    /** A hard ceiling on total URLs, so a runaway site cannot exhaust memory. */
    private const MAX_SHARDS = 20;

    public function __construct(
        private readonly ContentQueryServiceInterface $query,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteServiceInterface $sites,
        private readonly SeoService $seo,
        private readonly string $directory,
    ) {
    }

    /**
     * @param int|null $maxUrls Shard size. Overridable so a test can prove the splitting without
     *                          generating fifty thousand rows.
     */
    public function generate(int $siteId, ?int $maxUrls = null): int
    {
        $site = $this->sites->findById($siteId);
        $baseUrl = rtrim($site->baseUrl(), '/');

        $perShard = max(1, $maxUrls ?? self::MAX_URLS);
        $ceiling = $perShard * self::MAX_SHARDS;

        $entries = [];
        $offset = 0;
        $pageSize = 200;

        // Paged so a large site does not load everything into memory at once.
        while (count($entries) < $ceiling) {
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
                if (count($entries) >= $ceiling) {
                    break;
                }

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

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return 0;
        }

        $chunks = $entries === [] ? [[]] : array_chunk($entries, $perShard);

        // Stale shards from a previous, larger site must not survive: an index that points at a
        // shard which is no longer written is a broken sitemap. Collapsing to a single file means
        // removing shards from 1, not from 2.
        $keepShards = count($chunks) > 1 ? count($chunks) : 0;
        $this->removeShards($siteId, $keepShards + 1);

        if (count($chunks) === 1) {
            return $this->write($this->pathFor($siteId), $this->renderXml($chunks[0])) ? count($entries) : 0;
        }

        foreach ($chunks as $index => $chunk) {
            $written = $this->write(
                $this->pathForShard($siteId, $index + 1),
                $this->renderXml($chunk)
            );

            if (!$written) {
                return 0;
            }
        }

        $locations = [];

        foreach (array_keys($chunks) as $index) {
            $locations[] = $baseUrl . '/sitemap-' . ($index + 1) . '.xml';
        }

        return $this->write($this->pathFor($siteId), $this->renderIndex($locations)) ? count($entries) : 0;
    }

    public function pathFor(int $siteId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'sitemap-' . $siteId . '.xml';
    }

    public function pathForShard(int $siteId, int $shard): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'sitemap-' . $siteId . '-' . $shard . '.xml';
    }

    public function read(int $siteId): ?string
    {
        return $this->readFile($this->pathFor($siteId));
    }

    public function readShard(int $siteId, int $shard): ?string
    {
        if ($shard < 1) {
            return null;
        }

        return $this->readFile($this->pathForShard($siteId, $shard));
    }

    public function invalidate(int $siteId): void
    {
        $path = $this->pathFor($siteId);

        if (is_file($path)) {
            @unlink($path);
        }

        $this->removeShards($siteId, 1);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function readFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Delete shard files from $from upwards, so a shrinking site does not leave orphans behind.
     */
    private function removeShards(int $siteId, int $from): void
    {
        for ($shard = $from; $shard <= self::MAX_SHARDS + 1; $shard++) {
            $path = $this->pathForShard($siteId, $shard);

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function write(string $path, string $xml): bool
    {
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $xml) === false) {
            return false;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
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

    /**
     * @param list<string> $locations
     */
    private function renderIndex(array $locations): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($locations as $location) {
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            $lines[] = '  </sitemap>';
        }

        $lines[] = '</sitemapindex>';

        return implode("\n", $lines) . "\n";
    }
}
