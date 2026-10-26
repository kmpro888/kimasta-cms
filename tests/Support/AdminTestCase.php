<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;

/**
 * Test harness for the HTML admin.
 *
 * The API tests post JSON; an admin form posts url-encoded fields to `$_POST`. Simulating that
 * faithfully matters: form fields reach the application through the request array, not through
 * a JSON body, and a test that skipped that step would not exercise the same code path a
 * browser does.
 *
 * Everything else -- app boot, roles, site -- comes from AppTestCase.
 */
abstract class AdminTestCase extends AppTestCase
{
    /**
     * Fetch a page as the current session's user.
     */
    protected function get(string $path, array $query = []): Response
    {
        return $this->app->handle(Request::create('GET', $path, $this->host, $query));
    }

    /**
     * Submit a form.
     *
     * `$fields` lands in the request array, which is where `$_POST` ends up for a real
     * url-encoded form. `$files` mirrors `$_FILES` for a multipart form.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $files
     */
    protected function post(string $path, array $fields = [], bool $withCsrf = true, array $files = []): Response
    {
        if ($withCsrf && !array_key_exists('_token', $fields)) {
            $fields['_token'] = $this->token();
        }

        return $this->app->handle(Request::create(
            'POST',
            $path,
            $this->host,
            [],
            $fields,
            [],
            [],
            null,
            $files,
        ));
    }

    protected function token(): string
    {
        return (string) $this->app->container()->get(CsrfTokenManagerInterface::class)->token();
    }

    /**
     * Extract the first `_token` value from a rendered page.
     *
     * Proves the page actually carries a token rather than trusting the container to have one.
     */
    protected function tokenFromHtml(string $html): string
    {
        if (preg_match('/name="_token" value="([^"]+)"/', $html, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    protected function body(Response $response): string
    {
        return $response->content();
    }

    protected function location(Response $response): string
    {
        return (string) $response->header('Location');
    }
}
