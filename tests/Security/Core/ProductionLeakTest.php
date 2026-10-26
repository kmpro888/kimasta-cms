<?php

declare(strict_types=1);

namespace Tests\Security\Core;

use CMS\Core\Bootstrap\Application;
use CMS\Core\Http\ErrorResponse;
use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\ExceptionMiddleware;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Core\Http\RequestHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\TestCase;

/**
 * Production error responses must never expose internals.
 *
 * AGENTS.md forbids leaking SQL, passwords, secrets, environment variables,
 * filesystem paths and stack traces. These tests boot the real kernel from a
 * production-shaped .env (APP_DEBUG=false) and grep the raw response body for
 * every one of those categories.
 */
final class ProductionLeakTest extends TestCase
{
    /**
     * Substrings that must never appear in a production error response.
     *
     * @return array<string, array{0: string}>
     */
    public static function forbiddenFragments(): array
    {
        return [
            'windows drive path' => ['F:\\'],
            'posix root path' => ['/var/www'],
            'project directory' => ['line.id'],
            'trace key' => ['"trace"'],
            'exception key' => ['"exception"'],
            'file key' => ['"file"'],
            'stack frame marker' => ['Stack trace'],
            'pdo marker' => ['PDOException'],
            'sql select' => ['SELECT '],
            'sql insert' => ['INSERT INTO'],
            'db password key' => ['DB_PASSWORD'],
            'app key name' => ['APP_KEY'],
            'env value' => ['dev-key-change-me'],
            'php file suffix' => ['.php'],
            'namespace prefix' => ['CMS\\Core'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenFragments')]
    public function productionErrorResponseDoesNotLeak(string $fragment): void
    {
        $response = $this->productionApp()->handle(
            Request::create('GET', '/api/v1/does-not-exist')
        );

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString($fragment, $response->content());
    }

    #[Test]
    public function productionErrorResponseUsesFrozenEnvelope(): void
    {
        $response = $this->productionApp()->handle(
            Request::create('GET', '/api/v1/does-not-exist')
        );

        $decoded = json_decode($response->content(), true);

        self::assertIsArray($decoded);
        self::assertSame(['error'], array_keys($decoded));
        self::assertSame(['code', 'message', 'details'], array_keys($decoded['error']));
        self::assertSame('RESOURCE_NOT_FOUND', $decoded['error']['code']);
        self::assertSame([], $decoded['error']['details']);
    }

    #[Test]
    public function productionErrorResponseHasNoDebugBlock(): void
    {
        $response = $this->productionApp()->handle(
            Request::create('GET', '/api/v1/does-not-exist')
        );

        $decoded = json_decode($response->content(), true);

        self::assertArrayNotHasKey('debug', $decoded['error']['details']);
    }

    #[Test]
    public function productionGenericErrorHidesExceptionMessage(): void
    {
        $middleware = new ExceptionMiddleware(false);

        $response = $middleware->process(
            Request::create('GET', '/api/v1/anything'),
            $this->throwingHandler(new RuntimeException('SQLSTATE[42S02] table cms_users not found'))
        );

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('SQLSTATE', $response->content());
        self::assertStringNotContainsString('cms_users', $response->content());

        $decoded = json_decode($response->content(), true);

        self::assertSame('INTERNAL_ERROR', $decoded['error']['code']);
        self::assertSame('An unexpected error occurred.', $decoded['error']['message']);
    }

    #[Test]
    public function productionHttpExceptionKeepsItsPublicMessage(): void
    {
        $middleware = new ExceptionMiddleware(false);

        $response = $middleware->process(
            Request::create('GET', '/api/v1/anything'),
            $this->throwingHandler(HttpException::notFound())
        );

        self::assertSame(404, $response->status());

        $decoded = json_decode($response->content(), true);

        self::assertSame('Resource not found.', $decoded['error']['message']);
    }

    #[Test]
    public function debugModeIsOffForProductionFixture(): void
    {
        $app = $this->productionApp();

        /** @var \CMS\Shared\Contracts\ConfigInterface $config */
        $config = $app->container()->get(\CMS\Shared\Contracts\ConfigInterface::class);

        self::assertFalse((bool) $config->get('app.debug', true));
        self::assertSame('production', $config->get('app.env'));
    }

    #[Test]
    public function processEnvironmentOverridesDotEnvFile(): void
    {
        // A stale .env saying APP_DEBUG=true must not win over a hosting panel
        // that sets APP_DEBUG=false at the process level.
        $envFile = $this->path('debug-true.env');
        file_put_contents($envFile, "APP_ENV=production\nAPP_DEBUG=true\n");

        $previous = getenv('APP_DEBUG');
        putenv('APP_DEBUG=false');
        $_ENV['APP_DEBUG'] = 'false';

        try {
            $app = Application::create(CMS_TEST_ROOT, $envFile);

            /** @var \CMS\Shared\Contracts\ConfigInterface $config */
            $config = $app->container()->get(\CMS\Shared\Contracts\ConfigInterface::class);

            self::assertFalse((bool) $config->get('app.debug', true));
        } finally {
            if ($previous === false) {
                putenv('APP_DEBUG');
                unset($_ENV['APP_DEBUG']);
            } else {
                putenv('APP_DEBUG=' . $previous);
                $_ENV['APP_DEBUG'] = $previous;
            }
        }
    }

    #[Test]
    public function uploadsDirectoryCannotExecutePhp(): void
    {
        $htaccess = CMS_TEST_ROOT . '/public/uploads/.htaccess';

        if (!is_file($htaccess)) {
            self::markTestSkipped('public/uploads/.htaccess is not present.');
        }

        $contents = (string) file_get_contents($htaccess);

        self::assertMatchesRegularExpression('/php_flag\\s+engine\\s+off/i', $contents);
    }

    /**
     * Boot the kernel in production mode.
     *
     * phpunit.xml sets APP_DEBUG=true for the whole test run, and Env
     * deliberately lets real process environment win over the .env file (a
     * hosting panel must be able to force production). So the process-level
     * override has to be removed for this test to observe production
     * behaviour; the value is restored afterwards.
     */
    private function productionApp(): Application
    {
        $this->withoutProcessEnv('APP_DEBUG', 'APP_ENV');

        $envFile = $this->path('production.env');
        file_put_contents(
            $envFile,
            "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=production-key\n"
        );

        $app = Application::create(CMS_TEST_ROOT, $envFile);
        $app->boot();

        return $app;
    }

    /**
     * Remove process environment variables for the duration of the test.
     */
    private function withoutProcessEnv(string ...$keys): void
    {
        static $restore = null;

        if ($restore === null) {
            $restore = [];

            register_shutdown_function(static function () use (&$restore): void {
                foreach ($restore as $key => $value) {
                    if ($value === null) {
                        putenv($key);
                        unset($_ENV[$key], $_SERVER[$key]);

                        continue;
                    }

                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            });
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $restore)) {
                continue;
            }

            $value = getenv($key);
            $restore[$key] = $value === false ? null : $value;

            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function throwingHandler(\Throwable $exception): RequestHandlerInterface
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
}
