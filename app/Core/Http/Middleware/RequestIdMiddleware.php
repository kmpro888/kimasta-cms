<?php

declare(strict_types=1);

namespace CMS\Core\Http\Middleware;

use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;

/**
 * Assigns a stable request identifier to every request.
 *
 * An inbound X-Request-ID is reused only when explicitly trusted (reverse
 * proxy setups); otherwise a fresh ID is generated. The ID is echoed on the
 * response so clients and logs can be correlated.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Request-ID';

    /** @var (callable(): string)|null */
    private $generator;

    public function __construct(
        private readonly bool $trustIncoming = false,
        ?callable $generator = null,
    ) {
        $this->generator = $generator;
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $requestId = null;

        if ($this->trustIncoming) {
            $incoming = $request->header(self::HEADER);

            if (is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) === 1) {
                $requestId = $incoming;
            }
        }

        if ($requestId === null) {
            $requestId = $this->generate();
        }

        $request = $request->withRequestId($requestId);
        $response = $next->handle($request);

        return $response->setHeader(self::HEADER, $requestId);
    }

    private function generate(): string
    {
        if ($this->generator !== null) {
            return 'req_' . (string) ($this->generator)();
        }

        return 'req_' . bin2hex(random_bytes(12));
    }
}
