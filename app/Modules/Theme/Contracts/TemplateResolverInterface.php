<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Contracts;

use CMS\Modules\Theme\Entity\TemplateContext;

/**
 * Resolves which template file renders a given request.
 *
 * Implements the documented hierarchy with explicit fallbacks, so a theme only has
 * to provide `index.php` and still works:
 *
 *   single-{type}.php → single.php → index.php
 *   archive-{type}.php → archive.php → index.php
 *   page.php → index.php
 *   taxonomy.php / author.php / search.php / home.php / 404.php → index.php
 *
 * FROZEN CONTRACT.
 */
interface TemplateResolverInterface
{
    /**
     * Absolute path to the template that should render this context.
     *
     * @throws \CMS\Modules\Theme\Exceptions\TemplateNotFoundException
     */
    public function resolve(string $themeName, TemplateContext $context): string;

    /**
     * The candidate filenames in priority order, before existence is checked.
     *
     * Exposed so tests and the admin can explain why a template was chosen.
     *
     * @return list<string>
     */
    public function candidates(TemplateContext $context): array;
}
