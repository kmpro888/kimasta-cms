<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Support;

/**
 * Slug generation for terms.
 *
 * Deliberately small and local: term slugs are scoped per (site, taxonomy), so
 * they do not share the content slug machinery. The transliteration table is
 * explicit rather than `iconv//TRANSLIT`, because iconv is not portable and a
 * public URL must not depend on the host OS.
 */
final class TermSlug
{
    private const MAX_LENGTH = 180;

    /** @var array<string, string> */
    private const MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];

    public static function make(string $text): string
    {
        $text = strtolower(trim($text));
        $text = strtr($text, self::MAP);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        if ($text === '') {
            return 'term';
        }

        if (strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(substr($text, 0, self::MAX_LENGTH), '-');
        }

        return $text;
    }
}
