<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Middleware;

use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;

/**
 * Gate in front of every authenticated admin route.
 *
 * Three jobs, in order:
 *
 *   1. an unauthenticated request is redirected to the login screen, keeping the requested
 *      path so the person lands where they meant to go;
 *   2. every unsafe method must carry a valid CSRF token -- verified here rather than per
 *      controller, so a new screen cannot forget it;
 *   3. every response is marked `no-store` and gets the security headers.
 *
 * The CSRF token is a form field (`_token`), not a header, because these are ordinary HTML
 * forms. The header form is still accepted so a scripted client behaves the same way.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $path = $request->path();
        $safe = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $isLogin = $path === '/admin/login';

        // The login form itself needs no session.
        if ($isLogin && $safe) {
            return $this->harden($next->handle($request));
        }

        // The login POST needs no session either, but it absolutely needs a token: a login
        // form without CSRF protection lets a third-party page sign the visitor in as
        // someone else, which is session fixation by another name.
        if ($isLogin) {
            return $this->harden(
                $this->csrfValid($request) ? $next->handle($request) : $this->forbidden()
            );
        }

        $actor = $request->attribute('admin.actor');

        if (!is_object($actor) || !method_exists($actor, 'authenticated') || !$actor->authenticated()) {
            if (!$safe) {
                // A form submitted after the session expired cannot be replayed; send the
                // person to sign in again rather than pretending the action happened.
                return $this->redirect('/admin/login');
            }

            return $this->redirect('/admin/login?next=' . rawurlencode($path));
        }

        if (!$safe && !$this->csrfValid($request)) {
            return $this->harden($this->forbidden());
        }

        return $this->harden($next->handle($request));
    }

    private function isPublic(string $path): bool
    {
        return $path === '/admin/login';
    }

    /**
     * Compare the submitted token with the session token.
     *
     * The token is read from the form field first because these are ordinary HTML forms, then
     * from the header so a scripted client behaves the same way.
     */
    private function csrfValid(Request $request): bool
    {
        $submitted = $request->input('_token');
        $token = is_string($submitted) && $submitted !== '' ? $submitted : $request->header('x-csrf-token');

        if ($token === null || $token === '') {
            return false;
        }

        return $this->csrf->validate($token);
    }

    private function forbidden(): Response
    {
        return new Response(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Session expired</title></head><body>'
            . '<h1>Session expired</h1>'
            . '<p>Your session expired. Reload the page and try again.</p>'
            . '<p><a href="/admin">Back to the dashboard</a></p>'
            . '</body></html>',
            403,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function redirect(string $location): Response
    {
        return $this->harden(new Response('', 303, ['Location' => $location]));
    }

    /**
     * Headers every admin response carries.
     *
     * `no-store` matters most: without it a shared machine can expose one person's drafts to
     * the next person who presses Back.
     */
    private function harden(Response $response): Response
    {
        return $response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'same-origin');
    }
}
