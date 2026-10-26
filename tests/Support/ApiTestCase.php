<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;

/**
 * API-specific harness: request plumbing on top of the real application.
 */
abstract class ApiTestCase extends AppTestCase
{
    /**
     * Issue a request through the real pipeline.
     *
     * Non-safe methods get a valid CSRF token by default, because every mutation route
     * requires one. Pass `$sendCsrf = false` or a bad `$csrf` to exercise rejection.
     */
    protected function call(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?string $csrf = null,
        bool $sendCsrf = true,
        array $files = [],
    ): Response {
        $headers = [];

        $unsafe = !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($unsafe && $sendCsrf) {
            $headers['X-CSRF-Token'] = $csrf
                ?? (string) $this->app->container()->get(CsrfTokenManagerInterface::class)->token();
        }

        $request = Request::create(
            $method,
            $path,
            $this->host,
            $query,
            [],
            $headers,
            [],
            $body === null ? null : (string) json_encode($body),
            $files,
        );

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $decoded = json_decode($response->content(), true);

        self::assertIsArray($decoded, 'Response was not JSON: ' . substr($response->content(), 0, 200));

        return $decoded;
    }
}
