<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Core\I18n\Translator;
use CMS\Modules\Theme\Entity\ViewModel;
use CMS\Shared\Contracts\ActorInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;
use CMS\Shared\Contracts\SiteContextInterface;

/**
 * Shared behaviour for every admin screen.
 *
 * Controllers translate HTTP into service calls and nothing else. They hold no rules about
 * what a valid article is: those live in the services, so the API and the forms cannot
 * disagree about them.
 *
 * Everything a template needs is assembled here (`html()`), which is why no view ever has to
 * remember to include the CSRF token or the navigation.
 */
abstract class AdminController
{
    private const FLASH_KEY = 'admin.flash';

    public function __construct(
        protected readonly AdminView $view,
        protected readonly SessionInterface $session,
        protected readonly CsrfTokenManagerInterface $csrf,
        protected readonly ActorProviderInterface $actors,
    ) {
    }

    protected function site(Request $request): SiteContextInterface
    {
        $site = $request->attribute('admin.site');

        if (!$site instanceof SiteContextInterface) {
            // Landing here means the route ran without the context middleware, which is a
            // wiring bug rather than a client error.
            throw HttpException::internalError('Tenant context is missing.');
        }

        return $site;
    }

    protected function siteId(Request $request): int
    {
        return $this->site($request)->id();
    }

    protected function actor(Request $request): ActorInterface
    {
        $actor = $request->attribute('admin.actor');

        if (!$actor instanceof ActorInterface) {
            throw HttpException::internalError('Actor context is missing.');
        }

        return $actor;
    }

    /**
     * @throws HttpException 401 then 403
     */
    protected function requireCapability(Request $request, string $capability): ActorInterface
    {
        $actor = $this->actor($request);

        if (!$actor->authenticated()) {
            throw HttpException::unauthenticated();
        }

        if (!$actor->can($capability)) {
            // A 403 page rather than a silent redirect: hiding the screen would leave the
            // person wondering whether they mistyped the URL.
            throw HttpException::forbidden('You do not have permission to do that.');
        }

        return $actor;
    }

    /**
     * Render a screen inside the admin layout.
     *
     * @param array<string, mixed> $data
     */
    protected function html(
        Request $request,
        string $view,
        string $title,
        array $data = [],
        int $status = 200,
    ): Response {
        $actor = $this->actor($request);

        $viewModel = new ViewModel(array_replace([
            'title' => $title,
            'view' => $view,
            'csrf' => $this->csrf->token(),
            'site' => $this->site($request),
            'actor' => $actor,
            'path' => $request->path(),
            'flash' => $this->takeFlash(),
            'errors' => [],
            'old' => [],
        ], $data), new Translator($this->site($request)->defaultLocale()));

        return (new Response($this->view->render($view, $viewModel), $status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]));
    }

    /**
     * Render a screen without the layout: the login form and error pages.
     *
     * @param array<string, mixed> $data
     */
    protected function bare(string $view, array $data, int $status = 200): Response
    {
        return new Response($this->view->renderBare($view, new ViewModel($data)), $status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * 303 after every successful mutation, so a refresh re-fetches instead of re-submitting.
     */
    protected function redirect(string $path): Response
    {
        return new Response('', 303, ['Location' => $path]);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->put(self::FLASH_KEY, ['type' => $type, 'message' => $message]);
    }

    /**
     * Read the flash message and clear it: it must appear once.
     *
     * @return array{type: string, message: string}|null
     */
    protected function takeFlash(): ?array
    {
        $flash = $this->session->get(self::FLASH_KEY);

        $this->session->forget(self::FLASH_KEY);

        if (!is_array($flash) || !isset($flash['message'])) {
            return null;
        }

        return [
            'type' => (string) ($flash['type'] ?? 'info'),
            'message' => (string) $flash['message'],
        ];
    }

    /**
     * Submitted values, so a form can be re-rendered without losing what was typed.
     *
     * @return array<string, mixed>
     */
    protected function input(Request $request): array
    {
        return $request->all();
    }

    protected function stringParam(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $request->attribute('route.' . $key, $default));

        if (is_array($value)) {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    protected function intParam(Request $request, string $key, int $default = 0): int
    {
        $value = $request->input($key, $request->attribute('route.' . $key, $default));

        return is_scalar($value) ? (int) $value : $default;
    }
}
