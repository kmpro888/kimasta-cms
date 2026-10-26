<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Entity;

/**
 * Prepared data handed to a template.
 *
 * Deliberately not an array and deliberately without a container: a template can
 * read values and nothing else. That is how "theme MUST NOT query database" is
 * enforced structurally rather than by convention.
 */
final class ViewModel
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * A child view model with extra keys merged in.
     *
     * @param array<string, mixed> $extra
     */
    public function with(array $extra): self
    {
        return new self(array_replace($this->data, $extra));
    }

    /**
     * Escape a value for HTML output. Templates use this rather than calling
     * htmlspecialchars directly, so escaping is never forgotten.
     */
    public function e(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
