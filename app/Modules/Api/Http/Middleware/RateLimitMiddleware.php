<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Middleware;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Core\Security\RateLimiter;

/**
 * Caps repeated submissions against an unauthenticated abuse target.
 *
 * The bucket is keyed by what an attacker cannot rotate for free: the verb, the
 * path (a comment on one article must not spend another article's budget), the
 * tenant host, and the client address.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
    ) {
    }

    /**
     * Built here so the login controller can clear exactly the bucket that this
     * middleware counted, without duplicating the key format.
     */
    public static function keyFor(Request $request): string
    {
        $address = $request->server('REMOTE_ADDR');
        $client = is_scalar($address) ? trim((string) $address) : '';

        // Only a fallback: REMOTE_ADDR is set by the web server, while the
        // forwarded header is whatever the caller sent.
        if ($client === '') {
            $client = trim((string) $request->header('x-forwarded-for'));
        }

        if ($client === '') {
            $client = 'unknown';
        }

        return $request->method()
            . ' ' . $request->path()
            . ' ' . $request->host()
            . ' ' . $client;
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $key = self::keyFor($request);

        if (!$this->limiter->attempt($key, $this->maxAttempts, $this->decaySeconds)) {
            // HttpException has no header channel: the exception middleware renders status,
            // code and details only, so Retry-After cannot be emitted without widening a
            // frozen contract. The wait is reported in details instead.
            //
            // RATE_LIMITED is the code the frozen REST vocabulary documents for 429, and the
            // one HttpException::tooManyRequests() already returns. Any other spelling would
            // be a second name for one condition.
            throw new HttpException(
                429,
                'Too many requests. Please try again later.',
                'RATE_LIMITED',
                ['retry_after' => $this->limiter->availableIn($key, $this->decaySeconds)],
            );
        }

        return $next->handle($request);
    }
}
