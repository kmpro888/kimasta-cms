<?php

declare(strict_types=1);

namespace CMS\Core\Config;

use CMS\Shared\Contracts\ConfigInterface;

/**
 * Dot-notation configuration repository.
 *
 * Values are loaded once at bootstrap from /config/*.php and immutable
 * afterwards; environment access is centralised in EnvRepository so that
 * modules never call getenv() directly.
 */
final class Config implements ConfigInterface
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        if ($key === '') {
            return $this->items !== [];
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    /** @param array<string, mixed> $items */
    public function setMany(array $items): void
    {
        $this->items = array_replace_recursive($this->items, $items);
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        unset($cursor);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
