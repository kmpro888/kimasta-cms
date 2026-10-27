<?php

declare(strict_types=1);

namespace CMS\Core\I18n;

/**
 * File-backed catalogue lookup.
 *
 * `lang/en.php` returns a flat array. A locale with no catalogue — or a key missing from one — falls
 * back to the site's default locale, then to the key. That order matters: a half-translated site
 * shows English where it has no translation, never a blank or a raw identifier.
 *
 * Catalogs are loaded once per instance; the files are small and the alternative (a database table)
 * would put a query on every rendered page, which this project does not do for presentation.
 */
final class Translator implements TranslatorInterface
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    private readonly string $locale;

    private readonly string $basePath;

    public function __construct(
        string $locale,
        ?string $basePath = null,
        private readonly string $fallback = 'en',
    ) {
        // Default to the project root: pp/Core/I18n is three levels down. A caller that needs
        // another location (a test fixture) passes one.
        $this->basePath = $basePath ?? dirname(__DIR__, 3);
        $this->locale = $locale !== '' ? $locale : $fallback;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        $value = $this->catalog($this->locale)[$key]
            ?? $this->catalog($this->fallback)[$key]
            ?? $key;

        foreach ($params as $name => $replacement) {
            $value = str_replace(':' . $name, (string) $replacement, $value);
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return isset($this->catalog($this->locale)[$key])
            || isset($this->catalog($this->fallback)[$key]);
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        // `en_US` falls back to `en` when only the base language exists.
        $candidates = [$locale];

        if (str_contains($locale, '_')) {
            $candidates[] = explode('_', $locale)[0];
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $candidate) !== 1) {
                continue;
            }

            $path = rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . 'lang'
                . DIRECTORY_SEPARATOR . $candidate . '.php';

            if (!is_file($path)) {
                continue;
            }

            $catalog = require $path;

            if (is_array($catalog)) {
                return self::$cache[$locale] = $catalog;
            }
        }

        return self::$cache[$locale] = [];
    }
}
