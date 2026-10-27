<?php

declare(strict_types=1);

namespace CMS\Core\Routing;

/**
 * A single route definition.
 */
final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed $handler,
        private ?string $name = null,
    ) {
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): static
    {
        foreach ((array) $middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    /**
     * Match against a request path, returning captured parameters or null.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        $pattern = $this->compilePattern();

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($matches as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    private function compilePattern(): string
    {
        $segments = $this->splitSegments(trim($this->path, '/'));
        $parts = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            // Supports `{name}`, `{name?}` and `{name:<regex>}`.
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?::(.+))?\}$/', $segment, $matches) === 1) {
                $name = $matches[1];
                $optional = ($matches[2] ?? '') === '?';
                $constraint = $matches[3] ?? '[^/]+';

                $parts[] = $optional
                    ? '(?:/(?P<' . $name . '>' . $constraint . '))?'
                    : '/(?P<' . $name . '>' . $constraint . ')';

                continue;
            }

            // A parameter that shares its segment with literal text, e.g. `/sitemap-{shard}.xml`.
            //
            // Without this branch the whole segment fell through to preg_quote() below, so the
            // route compiled to the literal text `sitemap-{shard}.xml` and matched no real path:
            // a route that exists, passes review, and silently does nothing. Literal pieces are
            // still quoted; only the `{...}` tokens become captures.
            if (str_contains($segment, '{')) {
                $pieces = preg_split(
                    '/(\{[A-Za-z_][A-Za-z0-9_]*(?::[^}]+)?\})/',
                    $segment,
                    -1,
                    PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                ) ?: [$segment];

                $compiled = '';

                foreach ($pieces as $piece) {
                    if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::(.+))?\}$/', $piece, $inline) === 1) {
                        $compiled .= '(?P<' . $inline[1] . '>' . ($inline[2] ?? '[^/]+') . ')';

                        continue;
                    }

                    $compiled .= preg_quote($piece, '#');
                }

                $parts[] = '/' . $compiled;

                continue;
            }

            $parts[] = '/' . preg_quote($segment, '#');
        }

        return '#^' . implode('', $parts) . '/?$#';
    }

    /**
     * Split a path into segments on '/', ignoring slashes inside a `{...}` group.
     *
     * A naive explode() breaks any constraint that contains a slash — for example
     * `{path:(?!api/).*}` — which silently produces a route that never matches.
     *
     * @return list<string>
     */
    private function splitSegments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $segments = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($path);

        for ($index = 0; $index < $length; $index++) {
            $character = $path[$index];

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth = max(0, $depth - 1);
            }

            if ($character === '/' && $depth === 0) {
                $segments[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        return $segments;
    }
}
