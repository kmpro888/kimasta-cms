<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Service;

use CMS\Modules\Content\Contracts\BlockCompilerInterface;

/**
 * Minimal block compiler used until the real block engine (Agent 11) is wired.
 *
 * It understands only the shapes it can render safely and escapes all text, so
 * content saved today is stored with a correct `content_html` / `content_plain`
 * projection. Agent 11 replaces this binding with the full renderer; because
 * Content depends on the interface, that swap needs no change here.
 *
 * A fallback that silently emitted unescaped HTML would be a stored-XSS hole, so
 * escaping is unconditional.
 */
final class PlainBlockCompiler implements BlockCompilerInterface
{
    public function toHtml(array $blocks): string
    {
        $html = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $html[] = $this->renderBlock($block);
        }

        return implode("\n", array_filter($html, static fn (string $part): bool => $part !== ''));
    }

    public function toPlainText(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $text = $this->blockText($block);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');

        return match ($type) {
            'heading' => $this->renderHeading($block),
            'list' => $this->renderList($block),
            'quote' => '<blockquote>' . $this->escape($this->contentOf($block)) . '</blockquote>',
            'code' => '<pre><code>' . $this->escape($this->contentOf($block)) . '</code></pre>',
            'image' => $this->renderImage($block),
            default => $this->renderParagraph($block),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderHeading(array $block): string
    {
        $level = (int) ($block['data']['level'] ?? $block['level'] ?? 2);
        $level = max(1, min(6, $level));
        $text = $this->escape($this->contentOf($block));

        if ($text === '') {
            return '';
        }

        return sprintf('<h%d>%s</h%d>', $level, $text, $level);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderParagraph(array $block): string
    {
        $text = $this->escape($this->contentOf($block));

        if ($text === '') {
            return '';
        }

        // Preserve authored line breaks without allowing raw HTML through.
        return '<p>' . nl2br($text, false) . '</p>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderList(array $block): string
    {
        $items = $block['data']['items'] ?? $block['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return '';
        }

        $ordered = (bool) ($block['data']['ordered'] ?? false);
        $tag = $ordered ? 'ol' : 'ul';
        $html = [];

        foreach ($items as $item) {
            $html[] = '<li>' . $this->escape(is_scalar($item) ? (string) $item : '') . '</li>';
        }

        return '<' . $tag . '>' . implode('', $html) . '</' . $tag . '>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderImage(array $block): string
    {
        $src = (string) ($block['data']['url'] ?? $block['url'] ?? '');

        if ($src === '') {
            return '';
        }

        $alt = (string) ($block['data']['alt'] ?? $block['alt'] ?? '');

        return sprintf(
            '<img src="%s" alt="%s" loading="lazy">',
            $this->escape($src),
            $this->escape($alt)
        );
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockText(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');

        if ($type === 'list') {
            $items = $block['data']['items'] ?? $block['items'] ?? [];

            if (!is_array($items)) {
                return '';
            }

            return implode("\n", array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $items
            ));
        }

        return $this->contentOf($block);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function contentOf(array $block): string
    {
        $data = $block['data'] ?? null;

        if (is_array($data) && isset($data['text']) && is_scalar($data['text'])) {
            return (string) $data['text'];
        }

        if (isset($block['text']) && is_scalar($block['text'])) {
            return (string) $block['text'];
        }

        if (isset($block['content']) && is_scalar($block['content'])) {
            return (string) $block['content'];
        }

        return '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
