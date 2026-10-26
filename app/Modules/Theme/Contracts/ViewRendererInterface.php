<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Contracts;

use CMS\Modules\Theme\Entity\ViewModel;

/**
 * Renders a template against a prepared ViewModel.
 *
 * The single hard rule: **a theme must not query the database.** Everything a
 * template needs is already in the ViewModel, so the renderer never hands a
 * template a repository, a connection, or the container.
 *
 * FROZEN CONTRACT.
 */
interface ViewRendererInterface
{
    /**
     * Render a template file. Returns the output as a string; it does not echo.
     *
     * @throws \CMS\Modules\Theme\Exceptions\TemplateNotFoundException
     */
    public function render(string $templatePath, ViewModel $view): string;

    /**
     * Render a partial from the theme's `partials/` directory.
     *
     * @throws \CMS\Modules\Theme\Exceptions\TemplateNotFoundException
     */
    public function partial(string $themeName, string $partialName, ViewModel $view): string;
}
