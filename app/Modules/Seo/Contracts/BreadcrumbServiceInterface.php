<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Contracts;

/**
 * Breadcrumb trail construction and rendering.
 *
 * FROZEN CONTRACT.
 */
interface BreadcrumbServiceInterface
{
    /**
     * @return list<array{label: string, url: string}>
     */
    public function forContent(int $contentId, int $siteId): array;

    /**
     * Render the trail as HTML.
     *
     * @param list<array{label: string, url: string}> $items
     */
    public function render(array $items): string;

    /**
     * Render the trail as Schema.org BreadcrumbList JSON-LD.
     *
     * @param list<array{label: string, url: string}> $items
     */
    public function renderSchema(array $items): string;
}
