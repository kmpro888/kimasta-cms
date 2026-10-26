<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Session;

use CMS\Shared\Contracts\SessionInterface;
use RuntimeException;

/**
 * Native PHP session implementation of the frozen SessionInterface.
 *
 * Cookie flags are set before the session starts and are not negotiable:
 * HttpOnly always, SameSite=Lax, and Secure whenever the request is HTTPS. The
 * session id is regenerated on login and destroyed on logout, so a stolen
 * pre-login id cannot be escalated into an authenticated one.
 */
final class NativeSession implements SessionInterface
{
    private const COOKIE_NAME = 'cms_session';

    private const SESSION_KEY = '__cms';

    private bool $started = false;

    public function __construct(
        private readonly int $lifetimeMinutes = 120,
        private readonly bool $secure = false,
    ) {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot start session; headers already sent at %s:%d.', $file, $line));
        }

        session_name(self::COOKIE_NAME);

        session_set_cookie_params([
            'lifetime' => $this->lifetimeMinutes * 60,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Do not accept an attacker-chosen session id from the query string.
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        if (!session_start()) {
            throw new RuntimeException('Unable to start the session.');
        }

        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        /** @var array<string, mixed> $bag */
        $bag = $_SESSION[self::SESSION_KEY] ?? [];

        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();

        if (isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY][$key]);
        }
    }

    public function regenerate(): void
    {
        $this->start();

        // Delete the old session file so the previous id is immediately dead.
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
        $this->started = false;
    }

    public function all(): array
    {
        $this->start();

        /** @var array<string, mixed> $bag */
        $bag = $_SESSION[self::SESSION_KEY] ?? [];

        return $bag;
    }

    /**
     * Whether a session has been started in this process.
     */
    public function started(): bool
    {
        return $this->started || session_status() === PHP_SESSION_ACTIVE;
    }
}
