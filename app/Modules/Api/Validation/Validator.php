<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Validation;

/**
 * Minimal rule-based request validator.
 *
 * Deliberately small: the API layer validates *shape* (present, string, int,
 * max length). Business rules stay in the services, so validation is not
 * duplicated and an endpoint cannot drift from the domain it calls.
 *
 * Failures are collected rather than thrown one at a time, so a client sees every
 * problem in one response instead of fixing them one round trip at a time.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            $value = $this->data[$field] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                $this->addError($field, 'This field is required.');
            }
        }

        return $this;
    }

    public function string(string $field, ?int $maxLength = null): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $this;
        }

        if (!is_string($value)) {
            $this->addError($field, 'This field must be a string.');

            return $this;
        }

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            $this->addError($field, 'This field must not exceed ' . $maxLength . ' characters.');
        }

        return $this;
    }

    public function integer(string $field, ?int $min = null): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $this;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->addError($field, 'This field must be an integer.');

            return $this;
        }

        if ($min !== null && (int) $value < $min) {
            $this->addError($field, 'This field must be at least ' . $min . '.');
        }

        return $this;
    }

    public function boolean(string $field): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $this;
        }

        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            $this->addError($field, 'This field must be a boolean.');
        }

        return $this;
    }

    public function oneOf(string $field, array $allowed): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $this;
        }

        if (!in_array($value, $allowed, true)) {
            $this->addError($field, 'This value is not allowed.');
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= [];
        $this->errors[$field][] = $message;
    }
}
