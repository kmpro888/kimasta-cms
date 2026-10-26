<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Middleware;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Middleware\MiddlewareInterface;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Theme\Entity\ViewModel;
use Throwable;

/**
 * Turns an exception into an HTML page for an admin request.
 *
 * The global `ExceptionMiddleware` renders JSON, which is right for the API and wrong for a
 * screen: a person who mistypes a URL should not be handed `{"error":{"code":...}}`. This
 * middleware sits inside the route chain, so it catches before the global one and converts
 * to a page.
 *
 * It is the outermost admin middleware on purpose: an error raised while resolving the
 * tenant or the actor must also render as a page.
 */
final class AdminErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminView $view,
        private readonly bool $debug = false,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        try {
            return $next->handle($request);
        } catch (HttpException $exception) {
            return $this->page($exception->status(), $this->headingFor($exception->status()), $exception->getMessage());
        } catch (Throwable $exception) {
            // A stack trace is operator information, not visitor information. In production
            // the message never reaches the page; the log has it.
            $message = $this->debug
                ? $exception->getMessage()
                : 'Something went wrong. The error has been logged.';

            return $this->page(500, 'Something went wrong', $message);
        }
    }

    private function headingFor(int $status): string
    {
        return match (true) {
            $status === 404 => 'Page not found',
            $status === 403 => 'Access denied',
            $status === 429 => 'Slow down',
            $status >= 500 => 'Something went wrong',
            default => 'Request refused',
        };
    }

    private function page(int $status, string $heading, string $message): Response
    {
        $html = $this->view->renderBare('error', new ViewModel([
            'title' => $heading,
            'heading' => $heading,
            'message' => $message,
            'status' => $status,
        ]));

        return (new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']))
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
