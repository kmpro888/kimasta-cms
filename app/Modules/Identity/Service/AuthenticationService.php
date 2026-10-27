<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Service;

use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Entity\User;
use CMS\Modules\Identity\Exceptions\AccountDisabledException;
use CMS\Modules\Identity\Exceptions\InvalidCredentialsException;
use CMS\Modules\Identity\Repository\UserRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\SessionInterface;
use DateTimeImmutable;

/**
 * Password authentication.
 *
 * Two security properties are load-bearing here and must not be "simplified":
 *
 * 1. Failure is indistinguishable. An unknown identifier and a wrong password
 *    raise the same exception with the same message, and an unknown identifier
 *    still performs a password_verify against a dummy hash so the response time
 *    does not reveal whether the account exists.
 *
 * 2. The session id changes on login. Rotating it after authentication is what
 *    prevents session fixation; the old id is destroyed, not kept around.
 */
final class AuthenticationService implements AuthenticationServiceInterface
{
    private const SESSION_USER = 'auth.user_id';

    private const SESSION_FINGERPRINT = 'auth.fingerprint';

    private const SESSION_SITE = 'auth.site_id';

    /**
     * A real bcrypt hash of a random value, used only to burn the same CPU time
     * on the "user not found" path as on a genuine verification.
     */
    private const DUMMY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7YYfQ0jLhAqKxKQZ0Yy5nOaM1e0VQ3a';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly SessionInterface $session,
        private readonly ClockInterface $clock,
    ) {
    }

    public function login(string $identifier, string $password, int $siteId): User
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            throw new InvalidCredentialsException();
        }

        $user = $this->users->findByIdentifier($identifier, $siteId);

        if ($user === null) {
            // Constant-ish time: verify against a dummy so a missing account
            // does not answer measurably faster than a wrong password.
            password_verify($password, self::DUMMY_HASH);

            throw new InvalidCredentialsException();
        }

        if (!password_verify($password, $user->passwordHash)) {
            throw new InvalidCredentialsException();
        }

        // Password is correct from here on, so it is safe to be specific about
        // account state without leaking anything to an unauthorised caller.
        if (!$user->isActive()) {
            throw new AccountDisabledException();
        }

        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $rehashed = password_hash($password, PASSWORD_DEFAULT);

            if (is_string($rehashed)) {
                $this->users->updatePasswordHash(
                    $user->id ?? 0,
                    $siteId,
                    $rehashed,
                    $this->now()
                );

                $user = $user->withPasswordHash($rehashed, $this->now());
            }
        }

        $this->users->touchLastLogin($user->id ?? 0, $siteId, $this->now());
        $user = $user->withLastLoginAt($this->now());

        // Rotate before writing the identity, so the authenticated session is
        // always a fresh id.
        $this->session->regenerate();
        $this->session->put(self::SESSION_USER, $user->id);
        $this->session->put(self::SESSION_SITE, $siteId);
        // A fingerprint of the credential this session was created with. Changing the password —
        // from the profile screen, from an administrator's reset link, anywhere — changes it, and
        // every older session stops being authenticated on its next request. Without this, a
        // password change locks the door while leaving the other keys in the lock.
        $this->session->put(self::SESSION_FINGERPRINT, self::fingerprint($user->passwordHash));

        return $user;
    }

    public function logout(): void
    {
        $this->session->forget(self::SESSION_USER);
        $this->session->forget(self::SESSION_SITE);
        $this->session->forget(self::SESSION_FINGERPRINT);
        $this->session->destroy();
    }

    public function currentUserId(): ?int
    {
        $value = $this->session->get(self::SESSION_USER);

        if (!is_int($value) || $value <= 0) {
            return null;
        }

        $siteId = $this->currentSiteId();

        if ($siteId === null) {
            return null;
        }

        // The session must still match the credential it was created with.
        $user = $this->users->findById($value, $siteId);

        if ($user === null) {
            $this->logout();

            return null;
        }

        $stored = $this->session->get(self::SESSION_FINGERPRINT);

        if (!is_string($stored)) {
            // A session created before this check existed: adopt it once, then it behaves like any
            // other. After the deploy no new session can reach this branch.
            $this->session->put(self::SESSION_FINGERPRINT, self::fingerprint($user->passwordHash));

            return $value;
        }

        if (!hash_equals(self::fingerprint($user->passwordHash), $stored)) {
            // The password changed (or the account was recreated) since this session signed in.
            $this->logout();

            return null;
        }

        return $value;
    }

    private static function fingerprint(string $passwordHash): string
    {
        // A hash of the stored hash: the session never holds anything that could be replayed as a
        // credential, and the comparison is exact.
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    /**
     * Site id the current session authenticated against, if any.
     *
     * Kept separate from the user id so a session cannot be replayed against a
     * different tenant by editing one value.
     */
    public function currentSiteId(): ?int
    {
        $value = $this->session->get(self::SESSION_SITE);

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Change the password of an authenticated user and rotate the session.
     */
    public function changePassword(int $userId, int $siteId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash the new password.');
        }

        $this->users->updatePasswordHash($userId, $siteId, $hash, $this->now());
        $this->session->regenerate();
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
