<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Service;

use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Contracts\ViewRendererInterface;
use CMS\Modules\Theme\Entity\ViewModel;
use CMS\Modules\Theme\Exceptions\TemplateNotFoundException;
use Throwable;

/**
 * Renders a template file with a prepared ViewModel.
 *
 * The template receives exactly two things: `$view` (the ViewModel) and `$renderer`
 * (for partials). It does not receive a container, a repository or a connection —
 * that is what makes "theme MUST NOT query database" a structural guarantee rather
 * than a code-review habit.
 *
 * Output is buffered, so a template that echoes still returns a string and a
 * partial failure cannot leak half a page.
 */
final class ViewRenderer implements ViewRendererInterface
{
    public function __construct(private readonly ThemeRegistryInterface $themes)
    {
    }

    public function render(string $templatePath, ViewModel $view): string
    {
        if (!is_file($templatePath)) {
            throw new TemplateNotFoundException('Template does not exist: ' . basename($templatePath));
        }

        return $this->evaluate($templatePath, $view);
    }

    public function partial(string $themeName, string $partialName, ViewModel $view): string
    {
        $theme = $this->themes->get($themeName);
        $safe = $this->safePartialName($partialName);
        $path = $theme->partialsPath() . DIRECTORY_SEPARATOR . $safe . '.php';

        if (!is_file($path)) {
            throw new TemplateNotFoundException('Partial does not exist: ' . $safe);
        }

        return $this->evaluate($path, $view);
    }

    private function evaluate(string $path, ViewModel $view): string
    {
        $renderer = $this;

        ob_start();

        try {
            // Isolated scope: only $view and $renderer are visible to the template.
            (static function (string $__path, ViewModel $view, ViewRendererInterface $renderer): void {
                require $__path;
            })($path, $view, $renderer);
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }

    /**
     * Reject path traversal in a partial name.
     */
    private function safePartialName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new TemplateNotFoundException('Invalid partial name.');
        }

        return $name;
    }
}
