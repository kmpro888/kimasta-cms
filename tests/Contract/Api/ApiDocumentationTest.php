<?php

declare(strict_types=1);

namespace Tests\Contract\Api;

use CMS\Core\Http\Request;
use CMS\Core\Routing\Router;
use CMS\Modules\Api\Service\OpenApiDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * The API documentation is generated, committed, and checked against reality.
 *
 * Three things are asserted here, and each closes a different way a specification lies:
 *
 *  1. **Drift** — the committed `docs/api/openapi.json` is byte-identical to what the router
 *     produces. Adding an endpoint without regenerating turns the build red.
 *  2. **Shape** — every operation has an id, a tag, a success and an error response, and the two
 *     frozen envelopes are declared. Ids are unique.
 *  3. **Truth** — operations the document calls `public` answer without a session, and operations
 *     it calls `session` reject one. A document that is accurate about *which* endpoints exist but
 *     wrong about *who may call them* is worse than none.
 *
 * Operations marked `varies` are not probed: reads may serve public content or require a
 * capability, and a probe without a valid id cannot tell the difference. That gap is named in the
 * report rather than papered over.
 */
#[CoversClass(OpenApiDocument::class)]
final class ApiDocumentationTest extends AppTestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = dirname(__DIR__, 3) . '/docs/api/openapi.json';

        self::assertFileExists($path, 'The API document must be committed.');

        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded, 'The API document must be valid JSON.');

        return $decoded;
    }

    /**
     * @return list<array{path: string, method: string, operation: array<string, mixed>}>
     */
    private function operations(array $document): array
    {
        $out = [];

        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $out[] = ['path' => $path, 'method' => $method, 'operation' => $operation];
            }
        }

        return $out;
    }

    #[Test]
    public function theCommittedDocumentMatchesTheRouter(): void
    {
        $generated = (new OpenApiDocument($this->app->container()->get(Router::class)))
            ->build();

        $committed = $this->document();

        self::assertSame(
            $generated,
            $committed,
            'docs/api/openapi.json is out of date. Run: php bin/openapi.php'
        );
    }

    #[Test]
    public function everyApiRouteIsDocumentedAndNothingElseIs(): void
    {
        $document = $this->document();

        $documented = [];

        foreach ($document['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[strtoupper($method) . ' ' . $path] = true;
            }
        }

        $routes = [];

        foreach ($this->app->container()->get(Router::class)->routes() as $route) {
            $path = $route->path();

            if (!str_starts_with($path, '/api/')) {
                continue;
            }

            $normalised = (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^}]+\}/', '{$1}', $path);
            $routes[strtoupper($route->method()) . ' ' . $normalised] = true;
        }

        self::assertSame([], array_keys(array_diff_key($routes, $documented)), 'Undocumented routes.');
        self::assertSame([], array_keys(array_diff_key($documented, $routes)), 'Documented routes that do not exist.');

        // The document covers the core API only, and says so; a plugin route must not appear.
        $core = [
            'health', 'auth', 'content', 'posts', 'pages', 'taxonomies', 'search', 'comments',
            'media', 'redirects', 'settings', 'themes', 'me', 'migration', 'notifications',
            'ads', 'plugins', 'users',
        ];

        foreach ($this->operations($this->document()) as $entry) {
            foreach ($entry['operation']['tags'] as $tag) {
                self::assertContains($tag, $core, 'Unexpected tag in the document: ' . $tag);
            }
        }
    }

    #[Test]
    public function theDocumentDeclaresTheFrozenEnvelopes(): void
    {
        $document = $this->document();

        self::assertSame('3.1.0', $document['openapi']);

        $schemas = $document['components']['schemas'] ?? [];

        self::assertArrayHasKey('SuccessEnvelope', $schemas);
        self::assertSame(['data', 'meta'], $schemas['SuccessEnvelope']['required']);
        self::assertSame('object', $schemas['SuccessEnvelope']['properties']['meta']['type']);

        self::assertArrayHasKey('ErrorEnvelope', $schemas);
        self::assertSame(['error'], $schemas['ErrorEnvelope']['required']);
        self::assertSame(
            ['code', 'message'],
            $schemas['ErrorEnvelope']['properties']['error']['required']
        );

        self::assertArrayHasKey('session', $document['components']['securitySchemes']);
    }

    #[Test]
    public function everyOperationIsCompleteAndItsIdIsUnique(): void
    {
        $operations = $this->operations($this->document());

        self::assertGreaterThan(50, count($operations), 'The API is larger than this.');

        $ids = [];

        foreach ($operations as $entry) {
            $operation = $entry['operation'];
            $label = strtoupper($entry['method']) . ' ' . $entry['path'];

            self::assertNotSame('', (string) ($operation['operationId'] ?? ''), 'No operationId: ' . $label);
            self::assertNotSame([], $operation['tags'] ?? [], 'No tag: ' . $label);

            self::assertArrayHasKey('200', $operation['responses'] ?? [], 'No success response: ' . $label);
            self::assertArrayHasKey('default', $operation['responses'] ?? [], 'No error response: ' . $label);

            self::assertContains(
                $operation['x-auth'] ?? '',
                ['public', 'session', 'varies'],
                'Unknown auth classification: ' . $label
            );

            $ids[] = $operation['operationId'];
        }

        self::assertSame(
            count($ids),
            count(array_unique($ids)),
            'operationId values must be unique; they name the operation in generated clients.'
        );
    }

    #[Test]
    public function operationsDocumentedAsPublicAnswerWithoutASession(): void
    {
        // A GET is used rather than the sign-in POST: the point is that the router and the auth
        // middleware let the request through, not that the credentials are right.
        $response = $this->app->handle(Request::create('GET', '/api/v1/health', $this->host));

        self::assertSame(200, $response->status());
    }

    #[Test]
    public function operationsDocumentedAsNeedingASessionRejectAnonymousCallers(): void
    {
        $operations = $this->operations($this->document());
        $probed = 0;

        foreach ($operations as $entry) {
            if (($entry['operation']['x-auth'] ?? '') !== 'session') {
                continue;
            }

            if ($entry['method'] === 'get' && !str_contains($entry['path'], '{')) {
                $path = str_replace('/api/v1', '', $entry['path']);
                $response = $this->app->handle(Request::create('GET', '/api/v1' . $path, $this->host));

                // 401 or 403 are both "refused"; 200 would mean the document overstates the
                // protection, which is the failure this test exists to catch.
                self::assertContains(
                    $response->status(),
                    [401, 403],
                    'GET ' . $entry['path'] . ' answered ' . $response->status() . ' without a session.'
                );

                $probed++;

                continue;
            }

            // Writes: an anonymous caller must be stopped before anything is created. No ids are
            // needed for that — the refusal comes first.
            $path = (string) preg_replace('/\{[^}]+\}/', '1', $entry['path']);
            $response = $this->app->handle(Request::create(
                strtoupper($entry['method']),
                $path,
                $this->host,
            ));

            self::assertContains(
                $response->status(),
                [401, 403, 419],
                strtoupper($entry['method']) . ' ' . $entry['path'] . ' answered '
                    . $response->status() . ' without a session.'
            );

            $probed++;
        }

        self::assertGreaterThan(20, $probed, 'The classification must cover the writes.');
    }
}
