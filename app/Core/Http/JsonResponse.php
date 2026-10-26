<?php

declare(strict_types=1);

namespace CMS\Core\Http;

/**
 * JSON response implementing the frozen REST success envelope:
 *
 *   { "data": ..., "meta": {} }
 */
final class JsonResponse extends Response
{
    /**
     * @param mixed $data
     * @param array<string, mixed> $meta
     * @param array<string, string> $headers
     */
    public function __construct(
        mixed $data = null,
        int $status = 200,
        array $meta = [],
        array $headers = [],
    ) {
        // `meta` is always a JSON object, never an array: the frozen success
        // envelope requires `"meta": {}` even when empty.
        $payload = ['data' => $data, 'meta' => $meta === [] ? new \stdClass() : $meta];

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
