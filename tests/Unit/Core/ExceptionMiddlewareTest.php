<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\ExceptionMiddleware;
use CMS\Core\Http\Middleware\MiddlewarePipeline;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\Support\TestCase;

#[CoversClass(ExceptionMiddleware::class)]
final class ExceptionMiddlewareTest extends TestCase
{
    private function throwing(\Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private readonly \Throwable $exception)
            {
            }

            public function handle(Request $request): Response
            {
                throw $this->exception;
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $decoded = json_decode($response->content(), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testHttpExceptionBecomesFrozenErrorEnvelope(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new ExceptionMiddleware()],
            $this->throwing(HttpException::notFound()),
        );

        $response = $pipeline->handle(Request::create('GET', '/api/v1/articles/9'));
        $payload = $this->payload($response);

        self::assertSame(404, $response->status());
        self::assertSame(['code', 'message', 'details'], array_keys($payload['error']));
        self::assertSame('RESOURCE_NOT_FOUND', $payload['error']['code']);
    }

    public function testProductionDoesNotLeakExceptionDetails(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new ExceptionMiddleware(debug: false)],
            $this->throwing(new RuntimeException('SQLSTATE[HY000] password=hunter2 at /var/www/secret.php')),
        );

        $response = $pipeline->handle(Request::create('GET', '/api/v1/anything'));
        $payload = $this->payload($response);
        $body = $response->content();

        self::assertSame(500, $response->status());
        self::assertSame('INTERNAL_ERROR', $payload['error']['code']);
        self::assertSame([], $payload['error']['details']);
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('secret.php', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
    }

    public function testProductionPlainTextResponseLeaksNothing(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new ExceptionMiddleware(debug: false)],
            $this->throwing(new RuntimeException('DB password=hunter2')),
        );

        $response = $pipeline->handle(Request::create('GET', '/some-page'));

        self::assertSame('An unexpected error occurred.', $response->content());
        self::assertStringNotContainsString('hunter2', $response->content());
    }

    public function testDebugModeIncludesDiagnostics(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new ExceptionMiddleware(debug: true)],
            $this->throwing(new RuntimeException('boom')),
        );

        $response = $pipeline->handle(Request::create('GET', '/api/v1/anything'));
        $payload = $this->payload($response);

        self::assertSame('boom', $payload['error']['message']);
        self::assertArrayHasKey('debug', $payload['error']['details']);
        self::assertSame(RuntimeException::class, $payload['error']['details']['debug']['exception']);
    }
}
