<?php

declare(strict_types=1);

namespace Tests\Contract\Core;

use CMS\Core\Http\ErrorResponse;
use CMS\Core\Http\JsonResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Frozen REST envelope contract.
 *
 * These assertions must not change without an approved
 * CONTRACT_CHANGE_REQUEST: clients and every module depend on the exact
 * shapes asserted here.
 */
final class RestEnvelopeTest extends TestCase
{
    #[Test]
    public function successEnvelopeHasDataAndMeta(): void
    {
        $response = new JsonResponse(['id' => 5]);
        $decoded = $this->decode($response->content());

        self::assertSame(['data', 'meta'], array_keys($decoded));
        self::assertSame(['id' => 5], $decoded['data']);
        self::assertSame(200, $response->status());
    }

    #[Test]
    public function successEnvelopeMetaEncodesAsJsonObject(): void
    {
        $response = new JsonResponse(['ok' => true]);

        self::assertStringContainsString('"meta":{}', $this->compact($response->content()));
    }

    #[Test]
    public function successEnvelopeWithMetaMergesProvidedKeys(): void
    {
        $response = new JsonResponse(['items' => []], 200, ['total' => 0]);
        $decoded = $this->decode($response->content());

        self::assertSame(0, $decoded['meta']['total']);
    }

    #[Test]
    public function successEnvelopeContentTypeIsJson(): void
    {
        $response = new JsonResponse([]);

        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
    }

    #[Test]
    public function errorEnvelopeHasErrorKeyWithCodeAndMessage(): void
    {
        $response = new ErrorResponse('RESOURCE_NOT_FOUND', 'Not found.', 404);
        $decoded = $this->decode($response->content());

        self::assertSame(['error'], array_keys($decoded));
        self::assertSame('RESOURCE_NOT_FOUND', $decoded['error']['code']);
        self::assertSame('Not found.', $decoded['error']['message']);

        // The frozen envelope carries no "status" key; the HTTP status line
        // is the single source of truth for the code.
        self::assertArrayNotHasKey('status', $decoded['error']);
        self::assertSame(404, $response->status());
    }

    #[Test]
    public function errorEnvelopeAlwaysCarriesDetailsAsJsonObject(): void
    {
        $response = new ErrorResponse('INTERNAL_ERROR', 'Error.', 500);

        self::assertSame(['code', 'message', 'details'], array_keys($this->decode($response->content())['error']));
        self::assertStringContainsString('"details":{}', $this->compact($response->content()));
    }

    #[Test]
    public function errorEnvelopeIncludesDetailsWhenProvided(): void
    {
        $response = new ErrorResponse('VALIDATION_FAILED', 'Invalid.', 422, ['email' => 'Required.']);
        $decoded = $this->decode($response->content());

        self::assertSame(['email' => 'Required.'], $decoded['error']['details']);
    }

    #[Test]
    public function errorCodeIsUpperSnakeCase(): void
    {
        $response = new ErrorResponse('VALIDATION_FAILED', 'Invalid.', 422);
        $decoded = $this->decode($response->content());

        self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $decoded['error']['code']);
    }

    #[Test]
    public function errorEnvelopeContentTypeIsJson(): void
    {
        $response = new ErrorResponse('INTERNAL_ERROR', 'Error.', 500);

        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
    }

    /** @return array<string, mixed> */
    private function decode(string $content): array
    {
        $decoded = json_decode($content, true);

        self::assertIsArray($decoded, 'Envelope must be a JSON object.');

        return $decoded;
    }

    private function compact(string $content): string
    {
        return preg_replace('/\s+/', '', $content) ?? '';
    }
}
