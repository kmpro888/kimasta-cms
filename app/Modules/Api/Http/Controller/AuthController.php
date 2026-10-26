<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Core\Security\RateLimiter;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Http\Middleware\RateLimitMiddleware;
use CMS\Modules\Api\Resources\UserResource;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Identity\Contracts\AuthenticationServiceInterface;
use CMS\Modules\Identity\Contracts\PasswordResetServiceInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Exceptions\AccountDisabledException;
use CMS\Modules\Identity\Exceptions\InvalidCredentialsException;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;

/**
 * Authentication endpoints.
 *
 * Login errors are deliberately uniform: a wrong password and an unknown account
 * produce the same 401 with the same message, so the endpoint cannot be used to
 * enumerate accounts.
 */
final class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthenticationServiceInterface $auth,
        private readonly PasswordResetServiceInterface $resets,
        private readonly UserServiceInterface $users,
        private readonly UserResource $userResource,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * GET /auth/csrf
     *
     * Issues the token a client must send with every non-safe request. Separate from
     * login so the token exists before the first mutation.
     */
    public function csrf(Request $request): Response
    {
        return $this->ok(['token' => $this->csrf->token()]);
    }

    public function login(Request $request): Response
    {
        $data = $request->jsonBody();
        $validator = Validator::make($data)->required('identifier', 'password')->string('identifier', 255)->string('password', 255);

        $this->ensureValid($validator);

        try {
            $user = $this->auth->login(
                (string) $data['identifier'],
                (string) $data['password'],
                $this->siteId($request),
            );
        } catch (InvalidCredentialsException|AccountDisabledException) {
            throw new HttpException(401, 'Invalid credentials.', 'INVALID_CREDENTIALS');
        }

        // The credentials were right, so the typos that preceded them should not
        // keep counting against this address.
        $this->limiter->clear(RateLimitMiddleware::keyFor($request));

        return $this->ok($this->userResource->toArray($user));
    }

    public function logout(Request $request): Response
    {
        $this->requireAuthentication($request);
        $this->auth->logout();

        return new Response('', 204);
    }

    public function me(Request $request): Response
    {
        $actor = $this->requireAuthentication($request);

        $user = $this->users->find((int) $actor->id(), $this->siteId($request));

        if ($user === null) {
            throw HttpException::notFound();
        }

        return $this->ok($this->userResource->toArray($user));
    }

    /**
     * Always answers 204.
     *
     * Returning "no such account" would turn this into an account enumeration
     * endpoint. The token is delivered by mail, never by the response.
     */
    public function forgotPassword(Request $request): Response
    {
        $data = $request->jsonBody();
        $validator = Validator::make($data)->required('identifier')->string('identifier', 255);

        $this->ensureValid($validator);

        $this->resets->issue((string) $data['identifier'], $this->siteId($request));

        return new Response('', 204);
    }

    public function resetPassword(Request $request): Response
    {
        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('token', 'password')
            ->string('token', 255)
            ->string('password', 255);

        $this->ensureValid($validator);

        $userId = $this->resets->consume(
            (string) $data['token'],
            (string) $data['password'],
            $this->siteId($request),
        );

        if ($userId <= 0) {
            throw new HttpException(422, 'The reset token is invalid or expired.', 'VALIDATION_ERROR');
        }

        return new Response('', 204);
    }
}
