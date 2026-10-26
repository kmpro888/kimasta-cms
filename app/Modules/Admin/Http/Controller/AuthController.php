<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Core\Security\RateLimiter;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Api\Http\Middleware\RateLimitMiddleware;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Exceptions\AccountDisabledException;
use CMS\Modules\Identity\Exceptions\InvalidCredentialsException;
use CMS\Modules\Identity\Support\PasswordPolicy;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Admin sign-in and sign-out.
 *
 * The login screen is the only anonymous admin surface, so it carries the hardening: one
 * message for every credential failure (a form that says "no such account" enumerates
 * accounts), the same rate limiter the API login uses, and CSRF on the POST.
 */
final class AuthController extends AdminController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly AuthenticationServiceInterface $auth,
        private readonly RateLimiter $limiter,
        private readonly UserServiceInterface $users,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->actor($request)->authenticated()) {
            return $this->redirect('/admin');
        }

        return $this->loginPage($request);
    }

    public function login(Request $request): Response
    {
        $key = RateLimitMiddleware::keyFor($request);

        if (!$this->limiter->attempt($key, self::MAX_ATTEMPTS, self::DECAY_SECONDS)) {
            $wait = $this->limiter->availableIn($key, self::DECAY_SECONDS);

            return $this->loginPage(
                $request,
                'Too many attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).',
                429,
            );
        }

        $identifier = $request->input('identifier');
        $password = $request->input('password');

        if (!is_string($identifier) || !is_string($password) || $identifier === '' || $password === '') {
            return $this->loginPage($request, 'Enter your username and password.', 422);
        }

        try {
            $this->auth->login($identifier, $password, $this->siteId($request));
        } catch (InvalidCredentialsException|AccountDisabledException) {
            // One message for both, deliberately.
            return $this->loginPage($request, 'Those details are not correct.', 401);
        }

        // The credentials were right, so the typos before them should not keep counting.
        $this->limiter->clear($key);

        return $this->redirect($this->target($request));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return $this->redirect('/admin/login');
    }

    // ----------------------------------------------------------------- password

    public function showPassword(Request $request): Response
    {
        return $this->passwordPage($request);
    }

    /**
     * Change the signed-in person's own password.
     *
     * The current password is required even though the session is already authenticated: a
     * session left open on a shared machine should not be enough to lock the owner out.
     *
     * The new password is checked against the same policy the service enforces; the service is
     * still the authority, this only produces a better message before hashing.
     */
    public function changePassword(Request $request): Response
    {
        $actor = $this->actor($request);
        $siteId = $this->siteId($request);
        $userId = (int) $actor->id();

        $key = RateLimitMiddleware::keyFor($request);

        if (!$this->limiter->attempt($key, self::MAX_ATTEMPTS, self::DECAY_SECONDS)) {
            return $this->passwordPage($request, 'Too many attempts. Try again later.', 429);
        }

        $current = $this->stringParam($request, 'current_password');
        $new = $this->stringParam($request, 'new_password');
        $confirm = $this->stringParam($request, 'confirm_password');

        if ($current === '' || $new === '') {
            return $this->passwordPage($request, 'Fill in every field.', 422);
        }

        if ($new !== $confirm) {
            return $this->passwordPage($request, 'The new passwords do not match.', 422);
        }

        if (mb_strlen($new) < PasswordPolicy::MIN_LENGTH) {
            return $this->passwordPage(
                $request,
                'The new password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters.',
                422,
            );
        }

        $user = $this->users->find($userId, $siteId);

        if ($user === null) {
            throw \CMS\Core\Http\HttpException::notFound();
        }

        try {
            // Re-authenticate rather than trusting the session. A successful call also
            // regenerates the session id, which is the right thing after a credential change.
            $this->auth->login($user->username, $current, $siteId);
        } catch (InvalidCredentialsException|AccountDisabledException) {
            return $this->passwordPage($request, 'Your current password is not correct.', 422);
        }

        try {
            $this->users->changePassword($userId, $siteId, $new);
        } catch (\InvalidArgumentException $exception) {
            return $this->passwordPage($request, $exception->getMessage(), 422);
        }

        // The credentials were right, so earlier typos should not keep counting.
        $this->limiter->clear($key);

        $this->flash('success', 'Password changed.');

        return $this->redirect('/admin');
    }

    private function passwordPage(Request $request, ?string $error = null, int $status = 200): Response
    {
        return $this->html($request, 'password', 'Change password', [
            'error' => $error,
            'minimum' => PasswordPolicy::MIN_LENGTH,
        ], $status);
    }

    /**
     * Where to land after signing in.
     *
     * Only a local `/admin` path is followed. Accepting an arbitrary `next` turns the login
     * screen into an open redirect, which is a phishing tool.
     */
    private function target(Request $request): string
    {
        $next = $request->input('next');

        if (!is_string($next) || $next === '' || !str_starts_with($next, '/admin') || str_starts_with($next, '//')) {
            return '/admin';
        }

        return $next;
    }

    private function loginPage(Request $request, ?string $error = null, int $status = 200): Response
    {
        $next = $request->input('next');

        return $this->bare('login', [
            'title' => 'Sign in',
            'site' => $this->site($request),
            'csrf' => $this->csrf->token(),
            'error' => $error,
            'identifier' => is_string($request->input('identifier')) ? (string) $request->input('identifier') : '',
            'next' => is_string($next) ? $next : '',
        ], $status);
    }
}
