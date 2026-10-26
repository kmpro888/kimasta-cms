<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Middleware;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;

/**
 * CSRF protection for cookie-authenticated mutations.
 *
 * The API authenticates with the session cookie, so without this a third-party page
 * could make a logged-in user create, edit or delete content. That was the single
 * biggest security gap in the module.
 *
 * Rules:
 *
 *  - safe methods (GET, HEAD, OPTIONS) are unaffected;
 *  - every other method must carry the token in `X-CSRF-Token` or `_token`;
 *  - the token is compared with `hash_equals` inside the manager;
 *  - the token is obtained from `GET /api/v1/auth/csrf`, which sets it in the
 *    session, so a client must make one safe request before mutating.
 *
 * The token is required on **all** non-safe API requests, not only authenticated
 * ones. Login is included deliberately: login CSRF (forcing a victim into the
 * attacker's account) is a real attack, and exempting it would leave the hole open.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const HEADER = 'x-csrf-token';

    public function __construct(private readonly CsrfTokenManagerInterface $tokens)
    {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next->handle($request);
        }

        $provided = $this->tokenFrom($request);

        if ($provided === null || !$this->tokens->validate($provided)) {
            // 403, not the non-standard 419: Apache translates an unassigned status
            // code to 500, so 419 would reach the client as a server error. Clients
            // branch on the error code, which stays specific.
            throw new HttpException(
                403,
                'The CSRF token is missing or invalid.',
                'CSRF_TOKEN_MISMATCH'
            );
        }

        return $next->handle($request);
    }

    /**
     * Read the token from the header first, then the JSON/form body.
     */
    private function tokenFrom(Request $request): ?string
    {
        $header = $request->header(self::HEADER);

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $body = $request->jsonBody();

        if (isset($body['_token']) && is_string($body['_token']) && $body['_token'] !== '') {
            return $body['_token'];
        }

        $form = $request->input('_token');

        return is_string($form) && $form !== '' ? $form : null;
    }
}
