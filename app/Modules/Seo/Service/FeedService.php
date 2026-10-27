<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Service;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

/**
 * The site's RSS 2.0 feed, written as a file.
 *
 * Same shape as the sitemap and for the same reason: a public, cacheable document should be a read,
 * not a query. Cron rebuilds it; a request that finds no artifact builds it once and answers, so a
 * fresh install has a working feed without waiting for the clock.
 *
 * Twenty items is deliberate. A feed is a notification of what changed, not an archive; a client
 * that wants the archive reads the sitemap.
 */
final class FeedService
{
    private const ITEM_LIMIT = 20;

    public function __construct(
        private readonly ContentQueryServiceInterface $query,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteServiceInterface $sites,
        private readonly string $directory,
    ) {
    }

    public function generate(int $siteId): int
    {
        $site = $this->sites->findById($siteId);
        $baseUrl = rtrim($site->baseUrl(), '/');

        $result = $this->query->paginate($siteId, [
            'published_only' => true,
            'exclude_trash' => true,
            'type' => 'post',
            'limit' => self::ITEM_LIMIT,
            'offset' => 0,
            'order' => 'newest',
        ]);

        $items = $result['items'];

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return 0;
        }

        $xml = $this->render($site->name(), $baseUrl, $items);
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

        return count($items);
    }

    public function pathFor(int $siteId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'feed-' . $siteId . '.xml';
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

    /**
     * @param list<\CMS\Modules\Content\Entity\ContentSummary> $items
     */
    private function render(string $siteName, string $baseUrl, array $items): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '  <channel>',
            '    <title>' . $e($siteName) . '</title>',
            '    <link>' . $e($baseUrl . '/') . '</link>',
            '    <description>' . $e($siteName) . '</description>',
            '    <atom:link href="' . $e($baseUrl . '/feed') . '" rel="self" type="application/rss+xml"/>',
        ];

        foreach ($items as $item) {
            $link = $baseUrl . $this->urls->pathFor($item->type, $item->slug);
            $published = $item->publishedAt ?? $item->updatedAt;

            $lines[] = '    <item>';
            $lines[] = '      <title>' . $e((string) $item->title) . '</title>';
            $lines[] = '      <link>' . $e($link) . '</link>';
            $lines[] = '      <guid isPermaLink="true">' . $e($link) . '</guid>';

            if ($published instanceof \DateTimeImmutable) {
                $lines[] = '      <pubDate>' . $e($published->format(DATE_RSS)) . '</pubDate>';
            }

            if ($item->excerpt !== null && $item->excerpt !== '') {
                $lines[] = '      <description>' . $e($item->excerpt) . '</description>';
            }

            $lines[] = '    </item>';
        }

        $lines[] = '  </channel>';
        $lines[] = '</rss>';

        return implode("\n", $lines) . "\n";
    }
}
