<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Support;

use CMS\Modules\Theme\Contracts\ViewRendererInterface;
use CMS\Modules\Theme\Entity\ViewModel;
use CMS\Modules\Theme\Exceptions\TemplateNotFoundException;

/**
 * Renders an admin screen inside the admin layout.
 *
 * The admin reuses `ViewRendererInterface`, which gives the same structural guarantee the
 * public theme gets: a template sees only `$view` and `$renderer`, never a container, a
 * repository or a connection. An admin template therefore cannot query the database even by
 * accident.
 *
 * It does NOT reuse `partial()`, which resolves against a tenant theme. The admin layout
 * belongs to the application, not to a tenant, so a theme can never restyle or break it.
 *
 * Rendering is two passes: the screen first, then the layout with that HTML embedded. That
 * keeps every screen from repeating the shell.
 */
final class AdminView
{
    public function __construct(
        private readonly ViewRendererInterface $renderer,
        private readonly string $viewsPath,
    ) {
    }

    public function render(string $view, ViewModel $viewModel): string
    {
        $content = $this->renderer->render($this->path($view), $viewModel);

        return $this->renderer->render(
            $this->path('layout'),
            $viewModel->with(['content' => $content]),
        );
    }

    /**
     * A standalone page with no layout.
     *
     * Error pages and the login screen use this: an error must not depend on having resolved
     * a site or an actor, and a login screen has no navigation to show.
     */
    public function renderBare(string $view, ViewModel $viewModel): string
    {
        return $this->renderer->render($this->path($view), $viewModel);
    }

    /**
     * Resolve a view name to a file, rejecting anything that could escape the directory.
     *
     * View names are written in controller code, not supplied by a request, so this is a
     * structural guard rather than an input filter -- it costs nothing and removes the
     * question.
     */
    private function path(string $view): string
    {
        // Nested views (`content/form`) are allowed; escaping the directory is not. A strict
        // allow-list is simpler to reason about than a deny-list of traversal sequences.
        if ($view === '' || str_contains($view, '..') || str_starts_with($view, '/') || !preg_match('#^[A-Za-z0-9/_-]+$#', $view)) {
            throw new TemplateNotFoundException('Invalid admin view name.');
        }

        return $this->viewsPath . DIRECTORY_SEPARATOR . $view . '.php';
    }
}
