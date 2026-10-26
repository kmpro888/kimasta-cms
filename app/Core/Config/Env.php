<?php

declare(strict_types=1);

namespace CMS\Core\Config;

/**
 * Minimal .env reader.
 *
 * Supports KEY=value, quoted values, comments and `export` prefixes.
 * Process environment variables take precedence over file values when the
 * file does not define a key, so hosting panels can override safely.
 */
final class Env
{
    /** @var array<string, string> */
    private array $values = [];

    private bool $loaded = false;

    public function load(string $path): void
    {
        $this->loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($key === '') {
                continue;
            }

            $this->values[$key] = $this->normalise($value);
        }
    }

    public function loaded(): bool
    {
        return $this->loaded;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        // Real process environment wins over the .env file. Shared hosting
        // panels set APP_DEBUG / APP_ENV at the process level, and a stale
        // .env must never be able to switch a production host into debug
        // mode (that would leak stack traces, paths and SQL).
        $process = getenv($key);

        if ($process !== false && $process !== '') {
            return $process;
        }

        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        return $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }

    private function normalise(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);

                return $first === '"' ? stripcslashes($inner) : $inner;
            }
        }

        $comment = strpos($value, ' #');

        if ($comment !== false) {
            $value = rtrim(substr($value, 0, $comment));
        }

        return $value;
    }
}