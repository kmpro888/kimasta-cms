<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Container\Container;
use CMS\Core\Http\HttpException;
use CMS\Core\Http\JsonResponse;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Core\Routing\Route;
use CMS\Core\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router(new Container());
    }

    private static function ok(): Response
    {
        return new Response('ok', 200);
    }

    public function testMatchesStaticPath(): void
    {
        $router = $this->router();
        $router->get('/api/v1/health', static fn (): Response => self::ok());

        $match = $router->match(Request::create('GET', '/api/v1/health'));

        self::assertSame('/api/v1/health', $match->route->path());
    }

    public function testMatchesPathParameters(): void
    {
        $router = $this->router();
        $router->get('/api/v1/articles/{id}', static fn (): Response => self::ok());

        $match = $router->match(Request::create('GET', '/api/v1/articles/42'));

        self::assertSame(['id' => '42'], $match->parameters);
    }

    public function testParameterPatternIsEnforced(): void
    {
        $router = $this->router();
        $router->get('/articles/{id:\d+}', static fn (): Response => self::ok());

        $this->expectException(HttpException::class);

        $router->match(Request::create('GET', '/articles/not-a-number'));
    }

    public function testParameterPatternAcceptsMatchingValue(): void
    {
        $router = $this->router();
        $router->get('/articles/{id:\d+}', static fn (): Response => self::ok());

        $match = $router->match(Request::create('GET', '/articles/99'));

        self::assertSame(['id' => '99'], $match->parameters);
    }

    public function testTrailingSlashIsTolerated(): void
    {
        $router = $this->router();
        $router->get('/api/v1/health', static fn (): Response => self::ok());

        self::assertSame(
            '/api/v1/health',
            $router->match(Request::create('GET', '/api/v1/health/'))->route->path()
        );
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $router = $this->router();
        $router->get('/known', static fn (): Response => self::ok());

        try {
            $router->match(Request::create('GET', '/unknown'));
            self::fail('Expected HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status());
            self::assertSame('RESOURCE_NOT_FOUND', $exception->errorCode());
        }
    }

    public function testWrongMethodThrowsMethodNotAllowed(): void
    {
        $router = $this->router();
        $router->get('/resource', static fn (): Response => self::ok());

        try {
            $router->match(Request::create('POST', '/resource'));
            self::fail('Expected HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status());
            self::assertSame('METHOD_NOT_ALLOWED', $exception->errorCode());
        }
    }

    public function testHeadFallsBackToGetRoute(): void
    {
        $router = $this->router();
        $router->get('/resource', static fn (): Response => self::ok());

        $match = $router->match(Request::create('HEAD', '/resource'));

        self::assertSame('GET', $match->route->method());
    }

    public function testHeadResponseHasEmptyBody(): void
    {
        $router = $this->router();
        $router->get('/resource', static fn (): Response => new Response('body', 201, ['X-Test' => 'yes']));

        $response = $router->handle(Request::create('HEAD', '/resource'));

        self::assertSame(201, $response->status());
        self::assertSame('', $response->content());
        self::assertSame('yes', $response->header('X-Test'));
    }

    public function testNamedRouteIsRetrievable(): void
    {
        $router = $this->router();
        $router->get('/health', static fn (): Response => self::ok(), 'health');

        self::assertNotNull($router->named('health'));
        self::assertNull($router->named('missing'));
    }

    public function testHandlerReceivesRouteAttributes(): void
    {
        $router = $this->router();
        $seen = [];

        $router->get('/articles/{slug}', static function (Request $request) use (&$seen): Response {
            $seen['name'] = $request->attribute('route.name');
            $seen['slug'] = $request->attribute('route.slug');

            return self::ok();
        }, 'article.show');

        $router->handle(Request::create('GET', '/articles/hello-world'));

        self::assertSame('article.show', $seen['name']);
        self::assertSame('hello-world', $seen['slug']);
    }

    public function testContainerResolvedHandlerIsInvoked(): void
    {
        $container = new Container();
        $container->bind('test.handler', static fn (): callable => static fn (): Response => new Response('resolved'));

        $router = new Router($container);
        $router->get('/resolved', 'test.handler');

        self::assertSame('resolved', $router->handle(Request::create('GET', '/resolved'))->content());
    }

    public function testJsonResponseCarriesFrozenSuccessEnvelope(): void
    {
        $router = $this->router();
        $router->get('/json', static fn (): JsonResponse => new JsonResponse(['status' => 'ok']));

        $response = $router->handle(Request::create('GET', '/json'));

        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('{"data":{"status":"ok"},"meta":{}}', $response->content());
    }

    public function testHandlerReturningNonResponseIsRejected(): void
    {
        $router = $this->router();
        $router->get('/bad', static fn (): string => 'not a response');

        $this->expectException(HttpException::class);

        $router->handle(Request::create('GET', '/bad'));
    }

    /**
     * A constraint may itself contain a slash.
     *
     * compilePattern() used to split the path on '/' with a plain explode(), which
     * shredded any `{name:<regex>}` whose regex contained a slash and produced a
     * route that silently never matched.
     */
    public function testConstraintMayContainSlashes(): void
    {
        $route = new Route('GET', '/{path:(?!api/|admin/).*}', static fn (): Response => self::ok());

        $match = $route->match('/blog/hello-world');

        self::assertNotNull($match);
        self::assertSame('blog/hello-world', $match['path']);
    }

    public function testSlashedConstraintStillExcludesItsPrefixes(): void
    {
        $route = new Route('GET', '/{path:(?!api/|admin/).*}', static fn (): Response => self::ok());

        self::assertNull($route->match('/api/v1/health'));
        self::assertNull($route->match('/admin/agents'));
    }

    public function testCatchAllMatchesTheRootPath(): void
    {
        $route = new Route('GET', '/{path:(?!api/).*}', static fn (): Response => self::ok());

        $match = $route->match('/');

        self::assertNotNull($match);
        self::assertSame('', $match['path']);
    }

    public function testOrdinarySegmentRoutesAreUnaffectedByTheFix(): void
    {
        $route = new Route('GET', '/articles/{slug}', static fn (): Response => self::ok());

        self::assertSame(['slug' => 'hello'], $route->match('/articles/hello'));
    }

    public function testAnApiRouteWinsOverACatchAllRegisteredLater(): void
    {
        $router = $this->router();

        $router->get('/api/v1/health', static fn (): Response => new Response('health'));
        $router->get('/{path:(?!api/).*}', static fn (): Response => new Response('theme'));

        self::assertSame('health', $router->handle(Request::create('GET', '/api/v1/health'))->content());
        self::assertSame('theme', $router->handle(Request::create('GET', '/blog/x'))->content());
    }

    public function testAnUnknownApiPathFallsThroughToTheFrozenErrorEnvelope(): void
    {
        $router = $this->router();
        $router->get('/api/v1/health', static fn (): Response => new Response('health'));
        $router->get('/{path:(?!api/).*}', static fn (): Response => new Response('theme'));

        $this->expectException(HttpException::class);

        $router->handle(Request::create('GET', '/api/v1/nope'));
    }
}