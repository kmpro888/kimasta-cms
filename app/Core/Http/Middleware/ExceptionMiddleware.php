<?php

declare(strict_types=1);

namespace CMS\Core\Http\Middleware;

use CMS\Core\Http\ErrorResponse;
use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Converts uncaught exceptions into safe responses.
 *
 * Production responses never leak SQL, stack traces, filesystem paths or
 * environment values. Debug detail is only appended when APP_DEBUG is on.
 */
final class ExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        try {
            return $next->handle($request);
        } catch (HttpException $exception) {
            return $this->render($request, $exception);
        } catch (Throwable $exception) {
            $this->logger?->error('Unhandled exception', [
                'request_id' => $request->requestId(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return $this->render($request, $exception);
        }
    }

    private function render(Request $request, Throwable $exception): Response
    {
        $status = $exception instanceof HttpException
            ? $exception->status()
            : 500;

        $code = $exception instanceof HttpException
            ? $exception->errorCode()
            : 'INTERNAL_ERROR';

        $details = $exception instanceof HttpException
            ? $exception->details()
            : [];

        if ($this->debug) {
            $details = array_merge($details, [
                'debug' => [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => array_slice(
                        array_map(
                            static fn (array $frame): string => sprintf(
                                '%s:%s',
                                $frame['file'] ?? '[internal]',
                                $frame['line'] ?? '-'
                            ),
                            $exception->getTrace()
                        ),
                        0,
                        20
                    ),
                ],
            ]);
        }

        $message = $status === 500 && !$this->debug
            ? 'An unexpected error occurred.'
            : $exception->getMessage();

        if ($request->isJson() || $this->looksLikeApi($request)) {
            return new ErrorResponse($code, $message, $status, $details);
        }

        return new Response(
            $this->debug ? nl2br(htmlspecialchars($message, ENT_QUOTES)) : 'An unexpected error occurred.',
            $status,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    private function looksLikeApi(Request $request): bool
    {
        return str_starts_with($request->path(), '/api/');
    }
}