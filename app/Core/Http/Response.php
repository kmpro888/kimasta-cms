<?php

declare(strict_types=1);

namespace CMS\Core\Http;

/**
 * HTTP response value object.
 *
 * Header normalisation preserves the last value set per case-insensitive
 * name, which avoids duplicate headers on shared hosting where Apache may
 * already have emitted them.
 */
class Response
{
    /** @var array<string, array{name: string, value: string}> */
    private array $headers = [];

    public function __construct(
        private string $content = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = ['name' => $name, 'value' => $value];

        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)]['value'] ?? null;
    }

    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);

        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $result = [];

        foreach ($this->headers as $entry) {
            $result[$entry['name']] = $entry['value'];
        }

        return $result;
    }

    public function isEmpty(): bool
    {
        return $this->content === '';
    }

    /**
     * Emit the response. Kept isolated so tests can inspect output without
     * calling exit or emitting real headers.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $entry) {
                header($entry['name'] . ': ' . $entry['value'], true);
            }
        }

        echo $this->content;
    }
}
