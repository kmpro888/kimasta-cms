<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Service;

use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Contracts\SlugServiceInterface;

/**
 * Slug normalization and collision resolution.
 *
 * Slugs are part of a public URL, so normalization is deterministic: the same
 * title must always produce the same base slug. Uniqueness is per
 * (site, type, slug), which is exactly the database unique key, so the service
 * and the constraint agree.
 */
final class SlugService implements SlugServiceInterface
{
    /** Matches the `slug` column width. */
    private const MAX_LENGTH = 220;

    /**
     * Explicit Latin transliteration table.
     *
     * iconv('…//TRANSLIT') is not portable: on Windows it emits stray quotes and
     * spacing for accented input ("Café" becomes `caf-e`), which would make a
     * slug depend on the host OS. A URL must be deterministic, so the common
     * Latin range is mapped explicitly and iconv is only a fallback.
     *
     * @var array<string, string>
     */
    private const TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ð' => 'd', 'þ' => 'th',
    ];

    public function __construct(private readonly ContentRepositoryInterface $contents)
    {
    }

    public function slugify(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return 'untitled';
        }

        $text = strtolower($text);
        $text = strtr($text, self::TRANSLITERATION);

        // Anything left outside ASCII (Cyrillic, CJK, emoji) is dropped; iconv is
        // only a second chance for ranges the table does not cover.
        if (preg_match('/[^\x20-\x7e]/', $text) === 1 && function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

            if (is_string($converted) && $converted !== '') {
                $text = strtolower($converted);
            }
        }

        // Collapse anything that is not a-z0-9 into a single hyphen.
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        if ($text === '') {
            return 'untitled';
        }

        if (strlen($text) > self::MAX_LENGTH) {
            $text = substr($text, 0, self::MAX_LENGTH);
            // Do not end on a partial word boundary artefact.
            $text = rtrim($text, '-');
        }

        return $text;
    }

    public function uniqueFor(string $base, string $type, int $siteId, ?int $exceptId = null): string
    {
        $candidate = $this->slugify($base);

        if ($this->isAvailable($candidate, $type, $siteId, $exceptId)) {
            return $candidate;
        }

        // Deterministic suffix search. Each probe is one indexed lookup; in
        // practice the loop exits on the first or second attempt.
        $suffix = 2;

        while ($suffix < 1000) {
            $suffixed = $this->truncateForSuffix($candidate, '-' . $suffix);

            if ($this->isAvailable($suffixed, $type, $siteId, $exceptId)) {
                return $suffixed;
            }

            $suffix++;
        }

        // Extremely unlikely; fall back to a random tail rather than loop
        // forever or return a duplicate.
        return $this->truncateForSuffix($candidate, '-' . bin2hex(random_bytes(4)));
    }

    public function isAvailable(string $slug, string $type, int $siteId, ?int $exceptId = null): bool
    {
        if ($slug === '') {
            return false;
        }

        return !$this->contents->slugExists($slug, $type, $siteId, $exceptId);
    }

    private function truncateForSuffix(string $base, string $suffix): string
    {
        $max = self::MAX_LENGTH - strlen($suffix);

        if (strlen($base) > $max) {
            $base = rtrim(substr($base, 0, $max), '-');
        }

        return $base . $suffix;
    }
}
