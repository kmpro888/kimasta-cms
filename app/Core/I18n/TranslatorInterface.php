<?php

declare(strict_types=1);

namespace CMS\Core\I18n;

/**
 * Message lookup for one locale.
 *
 * Deliberately small: a catalogue is a PHP array, lookup is a key, and anything missing falls back
 * to the site's default locale and then to the key itself. A missing translation shows a readable
 * key rather than an empty screen, which is the failure mode a CMS can least afford.
 */
interface TranslatorInterface
{
    public function locale(): string;

    /**
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string;

    public function has(string $key): bool;
}
