<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Exceptions\InvalidRedirectException;
use CMS\Modules\Seo\Exceptions\RedirectLoopException;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Redirects from an old path to a new one.
 *
 * The service, the validation and the middleware that applies them already existed; only the screen
 * did not, so a redirect could only be added through the API or SQL.
 *
 * Authorization is `settings.update`: a redirect is site configuration, and it can send a public
 * URL anywhere, so it is not something an author should be able to change while writing.
 *
 * Validation stays in the service -- supported status codes, no root redirect, no protocol-relative
 * or off-site target that is not http(s), no self-redirect. This controller only turns a refusal
 * into a message on the form.
 */
final class RedirectController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly RedirectServiceInterface $redirects,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        return $this->page($request);
    }

    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $source = $this->stringParam($request, 'source_path');
        $target = $this->stringParam($request, 'target_url');
        $status = $this->intParam($request, 'status_code', 301);

        try {
            $this->redirects->store($this->siteId($request), $source, $target, $status);
        } catch (InvalidRedirectException|RedirectLoopException $exception) {
            // The service's message is written for a person, so it is shown as-is.
            return $this->page($request, $exception->getMessage(), [
                'source_path' => $source,
                'target_url' => $target,
                'status_code' => $status,
            ], 422);
        }

        $this->flash('success', 'Redirect added.');

        return $this->redirect('/admin/redirects');
    }

    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $source = $this->stringParam($request, 'source_path');

        if (!$this->redirects->delete($this->siteId($request), $source)) {
            $this->flash('error', 'That redirect was not found.');

            return $this->redirect('/admin/redirects');
        }

        $this->flash('success', 'Redirect deleted.');

        return $this->redirect('/admin/redirects');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function page(Request $request, ?string $error = null, array $values = [], int $status = 200): Response
    {
        return $this->html($request, 'redirects/index', 'Redirects', [
            'items' => $this->redirects->listForSite($this->siteId($request)),
            'error' => $error,
            'values' => $values + ['source_path' => '', 'target_url' => '', 'status_code' => 301],
            'statuses' => [301, 302, 307, 308],
        ], $status);
    }
}
