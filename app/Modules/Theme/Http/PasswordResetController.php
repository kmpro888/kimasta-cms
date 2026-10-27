<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;

/**
 * The public end of the password reset link.
 *
 * An administrator issues a one-time token and passes the link on; this is the page it lands on.
 * Until now the token existed and had nowhere to go, which made the whole chain useless.
 *
 * The response is a small self-contained document for the same reason the comment confirmation is:
 * it must render on any theme, and it must not be cached. `no-store` on every answer, because the
 * page contains a live credential while the form is open.
 *
 * The token is the secret and it is single use. There is no CSRF token here — an anonymous visitor
 * has no session to bind one to, and the credential itself is the proof.
 */
final class PasswordResetController implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteResolverInterface $sites,
        private readonly PasswordResetServiceInterface $resets,
    ) {
    }

    /**
     * The router resolves a class handler through `handle()`, so the method decides which half of
     * the flow this is: the form for a GET, the change for a POST.
     */
    public function handle(Request $request): Response
    {
        return strtoupper($request->method()) === 'POST'
            ? $this->consume($request)
            : $this->show($request);
    }

    /**
     * The form. A missing or unusable token is a page that says so, not a 404: the visitor arrived
     * from a link somebody sent them, and "not found" would read as "you typed it wrong".
     */
    public function show(Request $request): Response
    {
        $siteId = $this->siteIdFor($request);
        $token = $this->token($request);

        if ($token === '') {
            return $this->page(422, 'That link is incomplete', 'The reset link is missing its token. Ask for a new one.');
        }

        return $this->form($token, $siteId);
    }

    public function consume(Request $request): Response
    {
        $siteId = $this->siteIdFor($request);
        $token = $this->token($request);
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        if ($token === '') {
            return $this->page(422, 'That link is incomplete', 'The reset link is missing its token. Ask for a new one.');
        }

        if ($password !== $confirmation) {
            return $this->form($token, $siteId, 'The two passwords do not match.', 422);
        }

        if (mb_strlen($password) < \CMS\Modules\Identity\Support\PasswordPolicy::MIN_LENGTH) {
            return $this->form(
                $token,
                $siteId,
                'Use at least ' . \CMS\Modules\Identity\Support\PasswordPolicy::MIN_LENGTH . ' characters.',
                422
            );
        }

        try {
            $this->resets->consume($token, $password, $siteId);
        } catch (\InvalidArgumentException $exception) {
            return $this->form($token, $siteId, $exception->getMessage(), 422);
        } catch (\Throwable) {
            // An expired, spent or unknown token is one condition for the visitor.
            return $this->page(
                422,
                'That link no longer works',
                'It may have expired, or already been used. Ask an administrator for a new one.'
            );
        }

        return $this->page(
            200,
            'Password changed',
            'You can sign in with your new password now.',
            '/admin/login',
            'Sign in'
        );
    }

    private function form(string $token, int $siteId, ?string $error = null, int $status = 200): Response
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $minimum = \CMS\Modules\Identity\Support\PasswordPolicy::MIN_LENGTH;

        $errorHtml = $error === null
            ? ''
            : '<p class="error" role="alert">' . $e($error) . '</p>';

        $html = $this->shell('Choose a new password',
            '<h1>Choose a new password</h1>'
            . $errorHtml
            . '<form method="post" action="/reset-password">'
            . '<input type="hidden" name="token" value="' . $e($token) . '">'
            . '<label>New password'
            . '<input type="password" name="password" autocomplete="new-password" minlength="' . $minimum . '" required>'
            . '</label>'
            . '<label>Repeat it'
            . '<input type="password" name="password_confirmation" autocomplete="new-password" minlength="' . $minimum . '" required>'
            . '</label>'
            . '<button type="submit">Set password</button>'
            . '<p class="hint">At least ' . $minimum . ' characters.</p>'
            . '</form>'
        );

        return new Response($html, $status, $this->headers());
    }

    private function page(
        int $status,
        string $title,
        string $message,
        string $link = '/admin/login',
        string $linkLabel = 'Go to the sign-in page',
    ): Response {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = $this->shell(
            $title,
            '<h1>' . $e($title) . '</h1><p>' . $e($message) . '</p>'
            . '<p><a href="' . $e($link) . '">' . $e($linkLabel) . '</a></p>'
        );

        return new Response($html, $status, $this->headers());
    }

    private function shell(string $title, string $body): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . $e($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'max-width:26rem;margin:4rem auto;padding:0 1.25rem;line-height:1.6;color:#1f2933}'
            . 'h1{font-size:1.4rem;margin-bottom:1rem}'
            . 'label{display:block;margin-bottom:1rem;font-size:.9rem;font-weight:600}'
            . 'input{display:block;width:100%;margin-top:.35rem;padding:.6rem .7rem;font:inherit;'
            . 'border:1px solid #e4e7eb;border-radius:6px}'
            . 'button{margin-top:.5rem;padding:.6rem 1.1rem;font:inherit;font-weight:600;color:#fff;'
            . 'background:#1f2933;border:0;border-radius:6px;cursor:pointer}'
            . '.hint{color:#616e7c;font-size:.85rem}'
            . '.error{color:#b42318;background:#fef3f2;border:1px solid #fecdca;padding:.6rem .8rem;'
            . 'border-radius:6px;font-size:.9rem}'
            . 'a{color:#2b6cb0}</style></head><body>' . $body . '</body></html>';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            // The page holds a live credential while the form is open.
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    private function token(Request $request): string
    {
        $token = $request->input('token', '');

        return is_string($token) ? trim($token) : '';
    }

    private function siteIdFor(Request $request): int
    {
        try {
            return $this->sites->siteIdFor($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }
    }
}
