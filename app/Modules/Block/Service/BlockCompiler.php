<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Service;

use CMS\Modules\Block\Contracts\BlockMigrationInterface;
use CMS\Modules\Block\Contracts\BlockRegistryInterface;
use CMS\Modules\Block\Contracts\BlockRendererInterface;
use CMS\Modules\Block\Contracts\BlockSanitizerInterface;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;

/**
 * Compiles canonical block JSON into HTML and plain text.
 *
 * Pipeline, in this order:
 *
 *   migrate (old versions) → validate (known type) → sanitize (declared keys)
 *   → render (pure, no I/O) → html + plain
 *
 * **The renderer performs no database access.** Anything dynamic is passed in
 * pre-resolved through `render($blocks, $resolved)`, keyed by block index. That
 * is what makes a rendered page reproducible from its inputs and cheap to cache.
 *
 * This class implements Content's frozen `BlockCompilerInterface`, so Content
 * stores `content_html` / `content_plain` without knowing that a block engine
 * exists.
 */
final class BlockCompiler implements BlockCompilerInterface, BlockRendererInterface
{
    public function __construct(
        private readonly BlockRegistryInterface $registry,
        private readonly BlockSanitizerInterface $sanitizer,
        private readonly BlockMigrationInterface $migrator,
    ) {
    }

    public function toHtml(array $blocks): string
    {
        return $this->render($blocks)['html'];
    }

    public function toPlainText(array $blocks): string
    {
        return $this->render($blocks)['plain'];
    }

    public function render(array $blocks, array $resolved = []): array
    {
        $migrated = $this->migrator->migrateDocument($blocks);

        $html = [];
        $plain = [];

        foreach ($migrated as $index => $block) {
            $type = isset($block['type']) && is_string($block['type']) ? $block['type'] : '';

            // An unknown type renders nothing but is preserved in storage; a
            // disabled plugin must not break the whole page.
            if ($type === '' || !$this->registry->has($type)) {
                continue;
            }

            $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : [];

            $schema = $this->registry->schemaFor($type);
            $data = $this->sanitizer->sanitizeData($data, $schema);

            $rendered = $this->renderBlock($type, $data, $resolved[$index] ?? null);

            if ($rendered['html'] !== '') {
                $html[] = $rendered['html'];
            }

            if ($rendered['plain'] !== '') {
                $plain[] = $rendered['plain'];
            }
        }

        return [
            'html' => implode("\n", $html),
            'plain' => trim(implode("\n\n", $plain)),
        ];
    }

    public function dynamicBlockIndices(array $blocks): array
    {
        $indices = [];

        foreach ($blocks as $index => $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                continue;
            }

            if (!$this->registry->has($block['type'])) {
                continue;
            }

            if ($this->registry->get($block['type'])['dynamic']) {
                $indices[] = (int) $index;
            }
        }

        return $indices;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{html: string, plain: string}
     */
    private function renderBlock(string $type, array $data, mixed $resolved): array
    {
        return match ($type) {
            'paragraph' => $this->paragraph($data),
            'heading' => $this->heading($data),
            'image' => $this->image($data),
            'gallery' => $this->gallery($data),
            'quote' => $this->quote($data),
            'list' => $this->lists($data),
            'table' => $this->table($data),
            'button' => $this->button($data),
            'embed' => $this->embed($data, $resolved),
            'video' => $this->video($data),
            'code' => $this->code($data),
            'html' => $this->rawHtml($data),
            'columns' => $this->columns($data),
            'section' => $this->section($data),
            'hero' => $this->hero($data),
            'cta' => $this->cta($data),
            'faq' => $this->faq($data, 'faq'),
            'accordion' => $this->faq($data, 'accordion'),
            'related_posts' => $this->relatedPosts($data, $resolved),
            'ad_slot' => $this->adSlot($resolved),
            default => ['html' => '', 'plain' => ''],
        };
    }

    /** @param array<string, mixed> $data */
    private function paragraph(array $data): array
    {
        $text = $this->str($data, 'text');

        if ($text === '') {
            return ['html' => '', 'plain' => ''];
        }

        return [
            'html' => '<p>' . nl2br($this->e($text), false) . '</p>',
            'plain' => $text,
        ];
    }

    /** @param array<string, mixed> $data */
    private function heading(array $data): array
    {
        $text = $this->str($data, 'text');

        if ($text === '') {
            return ['html' => '', 'plain' => ''];
        }

        $level = (int) ($data['level'] ?? 2);
        $level = max(1, min(6, $level));

        return [
            'html' => sprintf('<h%d>%s</h%d>', $level, $this->e($text), $level),
            'plain' => $text,
        ];
    }

    /** @param array<string, mixed> $data */
    private function image(array $data): array
    {
        $url = $this->str($data, 'url');

        if ($url === '') {
            return ['html' => '', 'plain' => ''];
        }

        $alt = $this->str($data, 'alt');
        $caption = $this->str($data, 'caption');

        $html = sprintf('<img src="%s" alt="%s" loading="lazy">', $this->e($url), $this->e($alt));

        if ($caption !== '') {
            $html = '<figure>' . $html . '<figcaption>' . $this->e($caption) . '</figcaption></figure>';
        }

        return ['html' => $html, 'plain' => $alt];
    }

    /** @param array<string, mixed> $data */
    private function gallery(array $data): array
    {
        $ids = $data['media_ids'] ?? [];

        if (!is_array($ids) || $ids === []) {
            return ['html' => '', 'plain' => ''];
        }

        $columns = max(1, min(6, (int) ($data['columns'] ?? 3)));
        $html = ['<div class="gallery gallery--cols-' . $columns . '">'];

        foreach ($ids as $id) {
            $html[] = sprintf('<img src="" alt="" data-media-id="%d" loading="lazy">', (int) $id);
        }

        $html[] = '</div>';

        return ['html' => implode('', $html), 'plain' => ''];
    }

    /** @param array<string, mixed> $data */
    private function quote(array $data): array
    {
        $text = $this->str($data, 'text');

        if ($text === '') {
            return ['html' => '', 'plain' => ''];
        }

        $cite = $this->str($data, 'cite');
        $html = '<blockquote><p>' . nl2br($this->e($text), false) . '</p>';

        if ($cite !== '') {
            $html .= '<cite>' . $this->e($cite) . '</cite>';
        }

        $html .= '</blockquote>';

        return ['html' => $html, 'plain' => $text];
    }

    /** @param array<string, mixed> $data */
    private function lists(array $data): array
    {
        $items = $data['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return ['html' => '', 'plain' => ''];
        }

        $tag = !empty($data['ordered']) ? 'ol' : 'ul';
        $html = ['<' . $tag . '>'];
        $plain = [];

        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $html[] = '<li>' . $this->e((string) $item) . '</li>';
            $plain[] = (string) $item;
        }

        $html[] = '</' . $tag . '>';

        return [
            'html' => count($plain) === 0 ? '' : implode('', $html),
            'plain' => implode("\n", $plain),
        ];
    }

    /** @param array<string, mixed> $data */
    private function table(array $data): array
    {
        $rows = $data['rows'] ?? [];

        if (!is_array($rows) || $rows === []) {
            return ['html' => '', 'plain' => ''];
        }

        $hasHeader = !empty($data['header']);
        $html = ['<table>'];
        $plain = [];

        foreach (array_values($rows) as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            $cellTag = $hasHeader && $rowIndex === 0 ? 'th' : 'td';
            $html[] = '<tr>';
            $cells = [];

            foreach ($row as $cell) {
                if (!is_scalar($cell) && $cell !== null) {
                    continue;
                }

                $value = $this->e((string) $cell);
                $html[] = '<' . $cellTag . '>' . $value . '</' . $cellTag . '>';
                $cells[] = (string) $cell;
            }

            $html[] = '</tr>';
            $plain[] = implode(' | ', $cells);
        }

        $html[] = '</table>';

        return ['html' => implode('', $html), 'plain' => implode("\n", $plain)];
    }

    /** @param array<string, mixed> $data */
    private function button(array $data): array
    {
        $text = $this->str($data, 'text');
        $url = $this->str($data, 'url');

        if ($text === '' || $url === '') {
            return ['html' => '', 'plain' => ''];
        }

        $style = $this->str($data, 'style');

        return [
            'html' => sprintf(
                '<p class="block-button"><a class="button%s" href="%s">%s</a></p>',
                $style === '' ? '' : ' button--' . $this->e($style),
                $this->e($url),
                $this->e($text)
            ),
            'plain' => $text,
        ];
    }

    /**
     * Dynamic: the embed markup is supplied by the preparation stage.
     *
     * @param array<string, mixed> $data
     */
    private function embed(array $data, mixed $resolved): array
    {
        $url = $this->str($data, 'url');

        if (is_string($resolved) && $resolved !== '') {
            // Pre-resolved embed HTML is already sanitized by its resolver.
            return ['html' => $resolved, 'plain' => ''];
        }

        if ($url === '') {
            return ['html' => '', 'plain' => ''];
        }

        return [
            'html' => sprintf(
                '<div class="block-embed"><a href="%s" rel="noopener noreferrer">%s</a></div>',
                $this->e($url),
                $this->e($url)
            ),
            'plain' => $url,
        ];
    }

    /** @param array<string, mixed> $data */
    private function video(array $data): array
    {
        $url = $this->str($data, 'url');

        if ($url === '') {
            return ['html' => '', 'plain' => ''];
        }

        $poster = $this->str($data, 'poster');
        $attr = $poster === '' ? '' : ' poster="' . $this->e($poster) . '"';

        return [
            'html' => sprintf('<video controls preload="metadata" src="%s"%s></video>', $this->e($url), $attr),
            'plain' => '',
        ];
    }

    /** @param array<string, mixed> $data */
    private function code(array $data): array
    {
        $code = $this->str($data, 'code');

        if ($code === '') {
            return ['html' => '', 'plain' => ''];
        }

        $language = $this->str($data, 'language');
        $class = $language === '' ? '' : ' class="language-' . $this->e($language) . '"';

        return [
            'html' => '<pre><code' . $class . '>' . $this->e($code) . '</code></pre>',
            'plain' => $code,
        ];
    }

    /**
     * Raw HTML: the one block that intentionally bypasses escaping.
     *
     * It passes through the strict allowlist sanitizer instead. That is the
     * "special capability or strict sanitizer" rule from the task pack — the
     * capability decision happens before the block reaches the compiler, and the
     * sanitizer is unconditional.
     *
     * @param array<string, mixed> $data
     */
    private function rawHtml(array $data): array
    {
        $html = $this->str($data, 'html');

        if (trim($html) === '') {
            return ['html' => '', 'plain' => ''];
        }

        return [
            'html' => $this->sanitizer->sanitizeHtml($html),
            'plain' => trim(strip_tags($html)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function columns(array $data): array
    {
        $count = max(1, min(6, (int) ($data['columns'] ?? 2)));
        $children = $data['children'] ?? [];

        if (!is_array($children) || $children === []) {
            return ['html' => '', 'plain' => ''];
        }

        $html = ['<div class="block-columns block-columns--' . $count . '">'];

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $html[] = '<div class="block-column">' . $this->renderNested($child)['html'] . '</div>';
        }

        $html[] = '</div>';

        return ['html' => implode('', $html), 'plain' => ''];
    }

    /** @param array<string, mixed> $data */
    private function section(array $data): array
    {
        $children = $data['children'] ?? [];

        if (!is_array($children) || $children === []) {
            return ['html' => '', 'plain' => ''];
        }

        $background = $this->str($data, 'background');
        $class = 'block-section' . ($background === '' ? '' : ' block-section--' . $this->e($background));

        $html = ['<section class="' . $class . '">'];
        $plain = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $nested = $this->renderNested($child);
            $html[] = $nested['html'];

            if ($nested['plain'] !== '') {
                $plain[] = $nested['plain'];
            }
        }

        $html[] = '</section>';

        return ['html' => implode('', $html), 'plain' => implode("\n\n", $plain)];
    }

    /** @param array<string, mixed> $data */
    private function hero(array $data): array
    {
        $title = $this->str($data, 'title');

        if ($title === '') {
            return ['html' => '', 'plain' => ''];
        }

        $subtitle = $this->str($data, 'subtitle');
        $image = $this->str($data, 'image_url');
        $ctaText = $this->str($data, 'cta_text');
        $ctaUrl = $this->str($data, 'cta_url');

        $html = ['<section class="block-hero">'];

        if ($image !== '') {
            $html[] = sprintf('<img src="%s" alt="" loading="lazy">', $this->e($image));
        }

        $html[] = '<h2>' . $this->e($title) . '</h2>';

        if ($subtitle !== '') {
            $html[] = '<p>' . $this->e($subtitle) . '</p>';
        }

        if ($ctaText !== '' && $ctaUrl !== '') {
            $html[] = sprintf('<a class="button" href="%s">%s</a>', $this->e($ctaUrl), $this->e($ctaText));
        }

        $html[] = '</section>';

        return [
            'html' => implode('', $html),
            'plain' => trim($title . "\n" . $subtitle),
        ];
    }

    /** @param array<string, mixed> $data */
    private function cta(array $data): array
    {
        $title = $this->str($data, 'title');
        $text = $this->str($data, 'text');

        if ($title === '' && $text === '') {
            return ['html' => '', 'plain' => ''];
        }

        $buttonText = $this->str($data, 'button_text');
        $buttonUrl = $this->str($data, 'button_url');

        $html = ['<aside class="block-cta">'];

        if ($title !== '') {
            $html[] = '<h3>' . $this->e($title) . '</h3>';
        }

        if ($text !== '') {
            $html[] = '<p>' . $this->e($text) . '</p>';
        }

        if ($buttonText !== '' && $buttonUrl !== '') {
            $html[] = sprintf('<a class="button" href="%s">%s</a>', $this->e($buttonUrl), $this->e($buttonText));
        }

        $html[] = '</aside>';

        return ['html' => implode('', $html), 'plain' => trim($title . "\n" . $text)];
    }

    /**
     * FAQ and accordion share a shape: a list of question/answer pairs.
     *
     * @param array<string, mixed> $data
     */
    private function faq(array $data, string $class): array
    {
        $items = $data['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return ['html' => '', 'plain' => ''];
        }

        $html = ['<div class="block-' . $class . '">'];
        $plain = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = isset($item['question']) && is_scalar($item['question']) ? (string) $item['question'] : '';
            $answer = isset($item['answer']) && is_scalar($item['answer']) ? (string) $item['answer'] : '';

            if ($question === '') {
                continue;
            }

            $html[] = '<details><summary>' . $this->e($question) . '</summary>';
            $html[] = '<div>' . nl2br($this->e($answer), false) . '</div></details>';

            $plain[] = $question . "\n" . $answer;
        }

        $html[] = '</div>';

        return [
            'html' => count($plain) === 0 ? '' : implode('', $html),
            'plain' => implode("\n\n", $plain),
        ];
    }

    /**
     * Dynamic: post titles are resolved before rendering.
     *
     * @param array<string, mixed> $data
     */
    private function relatedPosts(array $data, mixed $resolved): array
    {
        $heading = $this->str($data, 'heading');

        if (!is_array($resolved) || $resolved === []) {
            return ['html' => '', 'plain' => ''];
        }

        $html = ['<aside class="block-related">'];

        if ($heading !== '') {
            $html[] = '<h3>' . $this->e($heading) . '</h3>';
        }

        $html[] = '<ul>';
        $plain = [];

        foreach ($resolved as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $title = isset($entry['title']) && is_scalar($entry['title']) ? (string) $entry['title'] : '';
            $url = isset($entry['url']) && is_scalar($entry['url']) ? (string) $entry['url'] : '';

            if ($title === '' || $url === '') {
                continue;
            }

            $html[] = sprintf('<li><a href="%s">%s</a></li>', $this->e($url), $this->e($title));
            $plain[] = $title;
        }

        $html[] = '</ul></aside>';

        return [
            'html' => count($plain) === 0 ? '' : implode('', $html),
            'plain' => implode("\n", $plain),
        ];
    }

    /**
     * Dynamic: the ad markup is resolved (and sanitized) before rendering.
     */
    private function adSlot(mixed $resolved): array
    {
        if (!is_string($resolved) || trim($resolved) === '') {
            return ['html' => '', 'plain' => ''];
        }

        return ['html' => $resolved, 'plain' => ''];
    }

    /**
     * Render a nested child block (used by columns/section).
     *
     * @param array<string, mixed> $child
     *
     * @return array{html: string, plain: string}
     */
    private function renderNested(array $child): array
    {
        $type = isset($child['type']) && is_string($child['type']) ? $child['type'] : '';

        if ($type === '' || !$this->registry->has($type)) {
            return ['html' => '', 'plain' => ''];
        }

        $data = isset($child['data']) && is_array($child['data']) ? $child['data'] : [];

        $data = $this->sanitizer->sanitizeData($data, $this->registry->schemaFor($type));

        return $this->renderBlock($type, $data, null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
