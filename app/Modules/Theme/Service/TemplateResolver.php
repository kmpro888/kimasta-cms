<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Service;

use CMS\Modules\Theme\Contracts\TemplateResolverInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Entity\TemplateContext;
use CMS\Modules\Theme\Exceptions\TemplateNotFoundException;

/**
 * Template hierarchy resolution.
 *
 * The candidate list is produced first and then checked for existence, so the
 * reason a template was chosen is inspectable instead of implicit.
 */
final class TemplateResolver implements TemplateResolverInterface
{
    public function __construct(private readonly ThemeRegistryInterface $themes)
    {
    }

    public function resolve(string $themeName, TemplateContext $context): string
    {
        $theme = $this->themes->get($themeName);
        $templates = $theme->templatesPath();

        foreach ($this->candidates($context) as $candidate) {
            $path = $templates . DIRECTORY_SEPARATOR . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        throw new TemplateNotFoundException(
            'No template found for "' . $context->kind . '" in theme "' . $themeName . '".'
        );
    }

    public function candidates(TemplateContext $context): array
    {
        $type = $context->contentType ?? 'post';

        return match ($context->kind) {
            TemplateContext::SINGLE => ['single-' . $type . '.php', 'single.php', 'index.php'],
            TemplateContext::PAGE => ['page.php', 'single.php', 'index.php'],
            TemplateContext::ARCHIVE => ['archive-' . $type . '.php', 'archive.php', 'index.php'],
            TemplateContext::TAXONOMY => ['taxonomy.php', 'archive.php', 'index.php'],
            TemplateContext::AUTHOR => ['author.php', 'archive.php', 'index.php'],
            TemplateContext::SEARCH => ['search.php', 'archive.php', 'index.php'],
            TemplateContext::HOME => ['home.php', 'index.php'],
            TemplateContext::NOT_FOUND => ['404.php', 'index.php'],
            default => ['index.php'],
        };
    }
}
