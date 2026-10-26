<?php

declare(strict_types=1);

namespace CMS\Core\Http;

/**
 * Immutable HTTP request value object.
 *
 * Superglobals are touched exactly once, in fromGlobals(), so the rest of
 * the application works against a plain object and tests can construct
 * requests without mutating process state.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $request
     * @param array<string, string> $headers Lower-cased header names.
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     * @param array<string, mixed>  $attributes
     */
    public function __construct(
        private string $method,
        private string $path,
        private string $host,
        private array $query = [],
        private array $request = [],
        private array $headers = [],
        private array $cookies = [],
        private array $server = [],
        private array $attributes = [],
        private ?string $body = null,
        private ?string $requestId = null,
        private array $files = [],
    ) {
        $this->method = strtoupper($method);
    }

    public static function fromGlobals(): self
    {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? (string) $_SERVER['REQUEST_METHOD']
            : 'GET';

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $host = isset($_SERVER['HTTP_HOST'])
            ? (string) $_SERVER['HTTP_HOST']
            : (isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : 'localhost');

        $headers = self::extractHeaders($_SERVER);
        $rawBody = file_get_contents('php://input');

        return new self(
            method: $method,
            path: $path,
            host: $host,
            query: is_array($_GET) ? $_GET : [],
            request: is_array($_POST) ? $_POST : [],
            headers: $headers,
            cookies: is_array($_COOKIE) ? array_map('strval', $_COOKIE) : [],
            server: is_array($_SERVER) ? $_SERVER : [],
            body: $rawBody === false ? null : $rawBody,
            files: is_array($_FILES) ? $_FILES : [],
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function scheme(): string
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        if ($https !== '' && $https !== 'off') {
            return 'https';
        }

        return (int) ($this->server['SERVER_PORT'] ?? 80) === 443 ? 'https' : 'http';
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host . $this->path;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_replace($this->query, $this->request, $this->jsonBody());
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * One uploaded file, normalised to a single shape.
     *
     * `$_FILES` uses a different layout for single and multi uploads, so the value is
     * normalised here rather than in every consumer. Core deliberately does not build a
     * module's `UploadedFile` value object: it hands back the raw datum and the owning
     * module maps it.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        $entry = $this->files[$key] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        // A multi-file field stores one array per property; this accessor is single-file.
        if (is_array($entry['tmp_name'] ?? null)) {
            return null;
        }

        return [
            'name' => (string) ($entry['name'] ?? ''),
            'type' => (string) ($entry['type'] ?? ''),
            'tmp_name' => (string) ($entry['tmp_name'] ?? ''),
            'error' => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($entry['size'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function body(): string
    {
        return $this->body ?? '';
    }

    /** @return array<string, mixed> */
    public function jsonBody(): array
    {
        $body = $this->body();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');

        return $contentType !== null && str_contains(strtolower($contentType), 'json');
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function withRequestId(string $requestId): self
    {
        $clone = clone $this;
        $clone->requestId = $requestId;

        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /** @param array<string, mixed> $server */
    public static function create(
        string $method,
        string $path,
        string $host = 'localhost',
        array $query = [],
        array $request = [],
        array $headers = [],
        array $cookies = [],
        ?string $body = null,
        array $files = [],
    ): self {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = (string) $value;
        }

        return new self(
            method: $method,
            path: $path,
            host: $host,
            query: $query,
            request: $request,
            headers: $normalised,
            cookies: $cookies,
            body: $body,
            files: $files,
        );
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = (string) $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['content-length'] = (string) $value;
            }
        }

        return $headers;
    }
}
