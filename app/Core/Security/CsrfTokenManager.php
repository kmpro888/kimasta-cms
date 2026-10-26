<?php

declare(strict_types=1);

namespace CMS\Core\Security;

use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Session-backed CSRF token manager.
 *
 * The frozen `CsrfTokenManagerInterface` existed from Agent 01 with no
 * implementation and no container binding, which meant every cookie-authenticated
 * mutation was CSRF-able. This is that implementation.
 *
 * Properties that matter:
 *
 *  - the token is stored server-side in the session, never derived from the user id
 *    or a timestamp, so it cannot be guessed from observable data;
 *  - comparison uses `hash_equals`, so validation is not a timing oracle;
 *  - the token is regenerated after login, alongside the session id rotation, so a
 *    token captured before authentication is worthless afterwards.
 */
final class CsrfTokenManager implements CsrfTokenManagerInterface
{
    private const SESSION_KEY = '_csrf_token';

    private const TOKEN_BYTES = 32;

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return $this->regenerate();
    }

    public function validate(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $expected = $this->session->get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }
}
