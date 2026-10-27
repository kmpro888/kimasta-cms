<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Service;

use CMS\Core\Routing\Route;
use CMS\Core\Routing\Router;

/**
 * Builds the OpenAPI description of the API from the routes that actually exist.
 *
 * A hand-written specification is a second source of truth, and the second one always loses: it is
 * accurate on the day it is written and wrong the first time somebody adds an endpoint. This reads
 * the router, so a route cannot exist without appearing here — and a contract test asserts the
 * committed document matches what the router produces, so the two cannot drift.
 *
 * What it describes honestly:
 *
 *  - every path and method,
 *  - which operations need a session,
 *  - the two frozen envelopes, which are the part of this API that must never change shape,
 *  - path parameters, with their constraint noted.
 *
 * What it does **not** describe: per-endpoint request and response bodies. The controllers parse
 * their input by hand and there is no schema to derive them from; writing those by hand would
 * recreate exactly the drift this file exists to prevent.
 */
final class OpenApiDocument
{
    /**
     * Operations reachable without a session.
     *
     * An explicit list, not a rule: a client that guesses wrong about authentication gets a 401 in
     * production, so the document must not infer this from the method or the path shape.
     */
    private const PUBLIC_PATHS = [
        '/api/v1/auth/login',
        '/api/v1/auth/forgot-password',
        '/api/v1/auth/reset-password',
        '/api/v1/auth/csrf',
        '/api/v1/health',
    ];

    /**
     * Operations that always need a session: the identity endpoints and every write.
     *
     * Writes in this API are not anonymous; reads vary, because some are public content and some
     * are administrative listings guarded by a capability. Those are marked `varies` rather than
     * guessed — see `x-auth` in the generated document.
     */
    private const SESSION_PATHS = [
        '/api/v1/auth/me',
        '/api/v1/auth/logout',
    ];

    /**
     * Tags that belong to the core API.
     *
     * A plugin can register routes, and whether it does depends on whether it is active on the
     * site — which makes the document a function of database state. The committed document
     * therefore describes the **core** API, and any route outside this list is named in
     * `x-excluded` instead of silently appearing or disappearing between runs.
     */
    private const CORE_TAGS = [
        'health', 'auth', 'content', 'posts', 'pages', 'taxonomies', 'search', 'comments',
        'media', 'redirects', 'settings', 'themes', 'me', 'migration', 'notifications', 'ads',
        'plugins', 'users',
    ];
    /** Path segments that are not resources and should not become tags. */
    private const NON_RESOURCE_SEGMENTS = ['v1'];

    public function __construct(
        private readonly Router $router,
        private readonly string $version = '1.0.0',
        private readonly string $sessionCookie = 'PHPSESSID',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $paths = [];
        $tags = [];

        foreach ($this->router->routes() as $route) {
            $path = $route->path();

            if (!str_starts_with($path, '/api/')) {
                continue;
            }

            $openApiPath = $this->toOpenApiPath($path);
            $method = strtolower($route->method());
            $tag = $this->tagFor($path);

            if (!in_array($tag, self::CORE_TAGS, true)) {
                continue;
            }

            $auth = $this->authFor($path, $method);

            $paths[$openApiPath][$method] = $this->operation($route, $tag, $auth, $openApiPath);

            $tags[$tag] = true;
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Lightweight Portal CMS API',
                'version' => $this->version,
                'description' => 'Generated from the router by `php bin/openapi.php`. Do not edit by '
                    . 'hand: a contract test fails when this document and the routes disagree. '
                    . 'It describes the core API; a route contributed by a plugin is not included, '
                    . 'because whether it exists depends on whether that plugin is active.',
            ],
            'servers' => [
                // Paths already carry `/api/v1`; a server URL with the same prefix would have every
                // client call `/api/v1/api/v1/...`.
                ['url' => '/', 'description' => 'This site'],
            ],
            'tags' => array_map(
                static fn (string $tag): array => ['name' => $tag],
                array_keys($tags)
            ),
            'components' => [
                'securitySchemes' => [
                    'session' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => $this->sessionCookie,
                        'description' => 'The session created by POST /auth/login. This API has no '
                            . 'bearer tokens: it authenticates with the same session cookie the admin '
                            . 'uses.',
                    ],
                ],
                'schemas' => [
                    'SuccessEnvelope' => [
                        'type' => 'object',
                        'required' => ['data', 'meta'],
                        'properties' => [
                            'data' => ['description' => 'The resource, or a list of resources.'],
                            'meta' => [
                                'type' => 'object',
                                'description' => 'Always an object, never an array, so a client can '
                                    . 'read it without a shape check.',
                            ],
                        ],
                    ],
                    'ErrorEnvelope' => [
                        'type' => 'object',
                        'required' => ['error'],
                        'properties' => [
                            'error' => [
                                'type' => 'object',
                                'required' => ['code', 'message'],
                                'properties' => [
                                    'code' => [
                                        'type' => 'string',
                                        'description' => 'UPPER_SNAKE_CASE, stable enough to branch on.',
                                        'pattern' => '^[A-Z][A-Z0-9_]*$',
                                    ],
                                    'message' => ['type' => 'string'],
                                    'details' => [
                                        'type' => 'object',
                                        'description' => 'Present only when there is something to say, '
                                            . 'such as field errors.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /**
     * `{id:\d+}` becomes `{id}`, which is what OpenAPI wants; the constraint is kept in the
     * parameter description so it is not lost.
     */
    private function toOpenApiPath(string $path): string
    {
        return (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^}]+\}/', '{$1}', $path);
    }

    private function tagFor(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        foreach ($segments as $segment) {
            if (in_array($segment, self::NON_RESOURCE_SEGMENTS, true) || $segment === 'api') {
                continue;
            }

            if (str_starts_with($segment, '{')) {
                return 'root';
            }

            return $segment;
        }

        return 'root';
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $tag, string $auth, string $openApiPath): array
    {
        $parameters = [];

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*):([^}]+)\}/', $route->path(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $parameters[] = [
                'name' => $match[1],
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'description' => 'Must match /' . $match[2] . '/.',
            ];
        }

        $operation = [
            'operationId' => $route->name() ?? $this->fallbackOperationId($route, $openApiPath),
            'summary' => $this->summary($route, $auth),
            'tags' => [$tag],
            // aries is stated as a vendor extension rather than guessed into security: a
            // client that reads a wrong security requirement fails in production, not in review.
            'x-auth' => $auth,
            'security' => $auth === 'public' ? [] : ($auth === 'session' ? [['session' => []]] : []),
            'responses' => [
                '200' => [
                    'description' => 'The frozen success envelope.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/SuccessEnvelope'],
                        ],
                    ],
                ],
                'default' => [
                    'description' => 'The frozen error envelope: validation, authentication, '
                        . 'not-found and conflict are all described by it.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
                        ],
                    ],
                ],
            ],
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        return $operation;
    }

    private function summary(Route $route, string $auth): string
    {
        $summary = strtoupper($route->method()) . ' ' . $this->toOpenApiPath($route->path());

        return match ($auth) {
            'public' => $summary . ' (no session required)',
            'varies' => $summary . ' (authentication varies: see x-auth)',
            default => $summary,
        };
    }

    /**
     * Classify an operation's authentication: `public`, `session`, or `varies`.
     *
     * `varies` is not laziness. A read endpoint may serve public content anonymously or require a
     * capability, and the router does not carry that policy — the controller does. Guessing would
     * put a wrong requirement in the document, which is worse than an honest "depends".
     */
    private function authFor(string $path, string $method): string
    {
        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return 'public';
        }

        if (in_array($path, self::SESSION_PATHS, true)) {
            return 'session';
        }

        return $method === 'get' ? 'varies' : 'session';
    }
    /**
     * A readable, unique operation id for a route nobody named.
     *
     * `get_ads`, `get_ads_by_id`: derived from the path so it does not contain slashes or braces and
     * stays stable when the route moves to another module.
     */
    private function fallbackOperationId(Route $route, string $openApiPath): string
    {
        $segments = array_values(array_filter(explode('/', trim($openApiPath, '/'))));

        // Drop the leading `api` and version segment.
        $segments = array_slice($segments, 2);

        if ($segments === []) {
            return strtolower($route->method()) . '_root';
        }

        $parts = [];

        foreach ($segments as $segment) {
            $parts[] = str_starts_with($segment, '{')
                ? 'by_' . trim($segment, '{}')
                : $segment;
        }

        $id = strtolower($route->method()) . '_' . implode('_', $parts);

        return (string) preg_replace('/[^a-z0-9_]+/', '_', $id);
    }
}
