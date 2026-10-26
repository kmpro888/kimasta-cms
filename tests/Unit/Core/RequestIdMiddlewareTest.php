<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Http\Middleware\MiddlewarePipeline;
use CMS\Core\Http\Middleware\RequestIdMiddleware;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TestCase;

#[CoversClass(RequestIdMiddleware::class)]
final class RequestIdMiddlewareTest extends TestCase
{
    private function capture(?
        Request &$captured = null
    ): RequestHandlerInterface {
        return new class ($captured) implements RequestHandlerInterface {
            public function __construct(private ?Request &$captured)
            {
            }

            public function handle(Request $request): Response
            {
                $this->captured = $request;

                return new Response('ok');
            }
        };
    }

    public function testGeneratesRequestIdWithFrozenPrefix(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new RequestIdMiddleware()],
            $this->capture($captured),
        );

        $response = $pipeline->handle(Request::create('GET', '/'));
        $id = $response->header('X-Request-ID');

        self::assertNotNull($id);
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/', $id);
        self::assertSame($id, $captured?->requestId());
    }

    public function testIncomingHeaderIgnoredByDefault(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new RequestIdMiddleware()],
            $this->capture(),
        );

        $request = Request::create('GET', '/', headers: ['X-Request-ID' => 'attacker-controlled']);
        $response = $pipeline->handle($request);

        self::assertNotSame('attacker-controlled', $response->header('X-Request-ID'));
    }

    public function testIncomingHeaderHonouredWhenTrusted(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new RequestIdMiddleware(trustIncoming: true)],
            $this->capture(),
        );

        $request = Request::create('GET', '/', headers: ['X-Request-ID' => 'proxy-req-0001']);
        $response = $pipeline->handle($request);

        self::assertSame('proxy-req-0001', $response->header('X-Request-ID'));
    }

    public function testMalformedIncomingHeaderIsReplaced(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware(
            [new RequestIdMiddleware(trustIncoming: true)],
            $this->capture(),
        );

        $request = Request::create('GET', '/', headers: ['X-Request-ID' => "bad id!\n"]);
        $response = $pipeline->handle($request);

        self::assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/', (string) $response->header('X-Request-ID'));
    }
}
