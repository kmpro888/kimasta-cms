<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use CMS\Core\Bootstrap\Application;
use CMS\Core\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * End-to-end check of the health endpoint through the real kernel.
 *
 * This is the smoke test that proves boot, container wiring, middleware and
 * routing cooperate. It deliberately asserts the frozen envelopes rather
 * than only the status code.
 */
final class HealthEndpointTest extends TestCase
{
    #[Test]
    public function healthEndpointReturnsFrozenSuccessEnvelope(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/v1/health'));

        self::assertSame(200, $response->status());

        $decoded = json_decode($response->content(), true);

        self::assertIsArray($decoded);
        self::assertSame(['data', 'meta'], array_keys($decoded));
        self::assertSame('ok', $decoded['data']['status']);
    }

    #[Test]
    public function healthEndpointDoesNotTrustClientSuppliedRequestId(): void
    {
        // Header injection into logs must not be possible from the outside,
        // so an untrusted inbound X-Request-ID is replaced, not echoed.
        $request = Request::create('GET', '/api/v1/health', headers: ['X-Request-ID' => 'req_abc123']);

        $response = $this->app()->handle($request);
        $echoed = (string) $response->header('X-Request-ID');

        self::assertNotSame('req_abc123', $echoed);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]+$/', $echoed);
    }

    #[Test]
    public function healthEndpointGeneratesRequestIdWhenAbsent(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/v1/health'));
        $requestId = (string) $response->header('X-Request-ID');

        self::assertMatchesRegularExpression('/^req_[0-9a-f]+$/', $requestId);
    }

    #[Test]
    public function unknownApiRouteReturnsFrozenErrorEnvelope(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/v1/does-not-exist'));

        self::assertSame(404, $response->status());

        $decoded = json_decode($response->content(), true);

        self::assertIsArray($decoded);
        self::assertSame(['error'], array_keys($decoded));
        self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $decoded['error']['code']);
        self::assertSame('RESOURCE_NOT_FOUND', $decoded['error']['code']);
        self::assertArrayHasKey('details', $decoded['error']);
    }

    #[Test]
    public function wrongMethodOnKnownRouteReturns405(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/api/v1/health'));

        self::assertSame(405, $response->status());
    }

    private function app(): Application
    {
        $app = Application::create(CMS_TEST_ROOT);
        $app->boot();

        return $app;
    }
}
