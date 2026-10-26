<?php

declare(strict_types=1);

namespace CMS\Core\Http;

/**
 * JSON error response implementing the frozen REST error envelope:
 *
 *   { "error": { "code": "...", "message": "...", "details": {} } }
 *
 * The envelope shape is frozen; do not add top-level keys.
 */
final class ErrorResponse extends Response
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
        array $headers = [],
    ) {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ];

        parent::__construct(
            content: (string) json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            status: $status,
            headers: $headers,
        );

        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
    }
}