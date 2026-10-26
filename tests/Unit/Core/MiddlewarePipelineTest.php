<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Middleware\MiddlewarePipeline;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TestCase;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    private function destination(string $body = 'destination'): RequestHandlerInterface
    {
        return new class ($body) implements RequestHandlerInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function handle(Request $request): Response
            {
                return new Response($this->body);
            }
        };
    }

    private function middleware(string $name, array &$log): MiddlewareInterface
    {
        return new class ($name, $log) implements MiddlewareInterface {
            /** @param list<string> $log */
            public function __construct(
                private readonly string $name,
                private array &$log,
            ) {
            }

            public function process(Request $request, RequestHandlerInterface $next): Response
            {
                $this->log[] = 'before:' . $this->name;
                $response = $next->handle($request);
                $this->log[] = 'after:' . $this->name;

                return $response->setHeader('X-Layer-' . $this->name, '1');
            }
        };
    }

    public function testEmptyPipelineCallsDestination(): void
    {
        $pipeline = MiddlewarePipeline::withMiddleware([], $this->destination('ok'));

        self::assertSame('ok', $pipeline->handle(Request::create('GET', '/'))->content());
    }

    public function testMiddlewareRunsInRegistrationOrderAsNestedLayers(): void
    {
        $log = [];

        $pipeline = MiddlewarePipeline::withMiddleware(
            [$this->middleware('outer', $log), $this->middleware('inner', $log)],
            $this->destination(),
        );

        $response = $pipeline->handle(Request::create('GET', '/'));

        self::assertSame(['before:outer', 'before:inner', 'after:inner', 'after:outer'], $log);
        self::assertSame('1', $response->header('X-Layer-outer'));
        self::assertSame('1', $response->header('X-Layer-inner'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $short = new class implements MiddlewareInterface {
            public function process(Request $request, RequestHandlerInterface $next): Response
            {
                return new Response('blocked', 403);
            }
        };

        $pipeline = MiddlewarePipeline::withMiddleware([$short], $this->destination('never'));
        $response = $pipeline->handle(Request::create('GET', '/'));

        self::assertSame(403, $response->status());
        self::assertSame('blocked', $response->content());
    }
}
