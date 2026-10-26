<?php

declare(strict_types=1);

namespace CMS\Core\Http;

use RuntimeException;

/**
 * Exception carrying an HTTP status and a machine-readable error code.
 *
 * Error codes follow the frozen REST convention: UPPER_SNAKE_CASE strings
 * such as RESOURCE_NOT_FOUND, VALIDATION_ERROR, INTERNAL_ERROR.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly string $errorCode = 'INTERNAL_ERROR',
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, $message, 'RESOURCE_NOT_FOUND');
    }

    public static function methodNotAllowed(string $message = 'Method not allowed.'): self
    {
        return new self(405, $message, 'METHOD_NOT_ALLOWED');
    }

    public static function badRequest(string $message = 'Bad request.'): self
    {
        return new self(400, $message, 'VALIDATION_ERROR');
    }

    public static function unauthenticated(string $message = 'Authentication required.'): self
    {
        return new self(401, $message, 'UNAUTHENTICATED');
    }

    public static function forbidden(string $message = 'Access denied.'): self
    {
        return new self(403, $message, 'FORBIDDEN');
    }

    public static function conflict(string $message = 'Resource conflict.'): self
    {
        return new self(409, $message, 'CONFLICT');
    }

    public static function unprocessable(string $message = 'The submitted data is invalid.'): self
    {
        return new self(422, $message, 'VALIDATION_ERROR');
    }

    public static function tooManyRequests(string $message = 'Too many requests.'): self
    {
        return new self(429, $message, 'RATE_LIMITED');
    }

    public static function internalError(string $message = 'Internal server error.'): self
    {
        return new self(500, $message, 'INTERNAL_ERROR');
    }
}