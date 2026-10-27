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
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data = [],
        private readonly ?\CMS\Core\I18n\TranslatorInterface $translator = null,
    ) {
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
        return new self(array_replace($this->data, $extra), $this->translator);
    }

    /**
     * A translated string for the site's locale.
     *
     * A template with no translator — and a key with no translation — returns the key itself, so a
     * half-configured site shows `nav.content` rather than a blank menu. That is deliberately the
     * less silent failure.
     *
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->translator?->t($key, $params) ?? $key;
    }

    public function locale(): string
    {
        return $this->translator?->locale() ?? 'en';
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
