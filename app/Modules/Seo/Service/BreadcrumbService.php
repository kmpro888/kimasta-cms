<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Service;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;

/**
 * Breadcrumb construction.
 *
 * Built from data the Content and Site contracts already expose, so a theme gets a
 * finished trail and never has to walk the CMS itself.
 */
final class BreadcrumbService implements BreadcrumbServiceInterface
{
    public function __construct(
        private readonly ContentServiceInterface $contents,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteServiceInterface $sites,
    ) {
    }

    public function forContent(int $contentId, int $siteId): array
    {
        $site = $this->sites->findById($siteId);

        $items = [[
            'label' => $site->name(),
            'url' => '/',
        ]];

        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            return $items;
        }

        // A page lives at the root, a post lives under its archive.
        if ($content->type !== 'page') {
            $items[] = [
                'label' => ucfirst($content->type) . 's',
                'url' => '/' . $content->type . 's',
            ];
        }

        $items[] = [
            'label' => $content->title,
            'url' => $this->urls->pathFor($content->type, $content->slug),
        ];

        return $items;
    }

    public function render(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $parts = [];
        $last = count($items) - 1;

        foreach (array_values($items) as $index => $item) {
            $label = $this->e((string) ($item['label'] ?? ''));
            $url = (string) ($item['url'] ?? '');

            if ($index === $last) {
                $parts[] = '<span aria-current="page">' . $label . '</span>';

                continue;
            }

            $parts[] = '<a href="' . $this->e($url) . '">' . $label . '</a>';
        }

        return '<nav class="breadcrumbs" aria-label="Breadcrumb">' . implode(' › ', $parts) . '</nav>';
    }

    public function renderSchema(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $elements = [];

        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => (string) ($item['label'] ?? ''),
                'item' => (string) ($item['url'] ?? ''),
            ];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];

        $encoded = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = str_replace('</', '<\/', (string) $encoded);

        return '<script type="application/ld+json">' . $encoded . '</script>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
