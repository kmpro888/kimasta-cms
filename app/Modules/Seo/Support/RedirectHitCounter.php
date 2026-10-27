<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Support;

/**
 * Counts redirect hits without writing to the database on every visit.
 *
 * The obvious implementation — `UPDATE redirects SET hit_count = hit_count + 1` in the redirect
 * path — puts a write on every visitor, which is the one thing this project forbids for public
 * traffic. A busy 301 would turn a shared host's disk into a queue.
 *
 * So a hit is one appended line in a file, and `bin/cron.php` folds the lines into `hit_count` in
 * batches. The trade is stated rather than hidden: hits are eventually consistent, and a crash
 * between the append and the flush can lose the lines that were not yet folded in. Counting is
 * approximate by design; not counting at all was the alternative.
 *
 * Appends are small and the file is opened in append mode, so concurrent visitors add lines rather
 * than overwrite each other. `drain()` renames the file first: readers that still hold the old
 * handle finish against the renamed file, and the next append creates a new one.
 */
final class RedirectHitCounter
{
    public function __construct(private readonly string $directory)
    {
    }

    public function record(int $siteId, string $path): void
    {
        $path = trim($path);

        if ($path === '') {
            return;
        }

        try {
            if (!is_dir($this->directory)) {
                @mkdir($this->directory, 0775, true);
            }

            // One line per hit. Newlines in the path are impossible after normalisation, and a
            // tab is used as a separator so a path may contain spaces.
            @file_put_contents($this->file($siteId), $path . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // A failed count must never fail the redirect the visitor asked for.
        }
    }

    /**
     * Read and clear the pending hits for a site.
     *
     * @return array<string, int> path => hits
     */
    public function drain(int $siteId): array
    {
        $file = $this->file($siteId);

        if (!is_file($file)) {
            return [];
        }

        $drained = $file . '.' . bin2hex(random_bytes(4));

        // Rename before reading: an append that arrives mid-flush lands in a fresh file instead of
        // being truncated away with the lines already read.
        if (!@rename($file, $drained)) {
            return [];
        }

        $counts = [];
        $handle = @fopen($drained, 'rb');

        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $path = rtrim($line, "\r\n");

                if ($path === '') {
                    continue;
                }

                $counts[$path] = ($counts[$path] ?? 0) + 1;
            }

            fclose($handle);
        }

        @unlink($drained);

        return $counts;
    }

    public function pending(int $siteId): int
    {
        $file = $this->file($siteId);

        if (!is_file($file)) {
            return 0;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }

    private function file(int $siteId): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $siteId . '.log';
    }
}
