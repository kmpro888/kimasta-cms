<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Support;

/**
 * Translates between authored content_json blocks and the flat fields the block
 * editor posts.
 *
 * The editor's rows are a presentation detail; types, defaults, level clamping
 * and the rule that an empty row is not content are content rules. Keeping the
 * translation here means a template can render and echo raw fields without
 * knowing the stored schema, and any future editor (API, import, CLI) reuses
 * the same normalisation instead of growing a second, subtly different copy.
 *
 * The stored shape is the one the public renderer already understands
 * (see Content\Service\PlainBlockCompiler): a list of
 * ['type' => string, 'version' => 1, 'data' => array].
 */
final class BlockFormCompiler
{
    /**
     * Menu order: the structural blocks first, then the inline media block.
     */
    private const TYPES = ['heading', 'paragraph', 'list', 'quote', 'code', 'image'];

    /**
     * Convert raw submitted form fields into content_json blocks.
     *
     * @param array<string, mixed> $input the raw request POST array
     * @return list<array<string, mixed>>
     */
    public function fromForm(array $input): array
    {
        $rows = $input['blocks'] ?? null;

        if (!is_array($rows)) {
            return [];
        }

        $blocks = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $block = $this->blockFromRow($row);

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return array_values($blocks);
    }

    /**
     * Convert content_json blocks into rows a template can render as form fields.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array{type: string, text: string, level: int, items: string, ordered: bool, url: string, alt: string}>
     */
    public function toForm(array $blocks): array
    {
        $rows = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $rows[] = $this->rowFromBlock($block);
        }

        return array_values($rows);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null null when the row holds no content
     */
    private function blockFromRow(array $row): ?array
    {
        $type = $this->normaliseType($row['type'] ?? null);
        $data = $this->rowData($row);

        return match ($type) {
            'heading' => $this->headingBlock($data),
            'list' => $this->listBlock($data),
            'image' => $this->imageBlock($data),
            default => $this->textBlock($type, $data),
        };
    }

    /**
     * A row left blank while editing is a placeholder, not content. Storing it
     * would make content_json accumulate holes that render to nothing and make
     * "is this article empty" impossible to answer.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function textBlock(string $type, array $data): ?array
    {
        $text = $this->stringValue($data['text'] ?? null);

        if (trim($text) === '') {
            return null;
        }

        return [
            'type' => $type,
            'version' => 1,
            'data' => ['text' => $this->normaliseText($type, $text)],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function headingBlock(array $data): ?array
    {
        $text = trim($this->stringValue($data['text'] ?? null));

        if ($text === '') {
            return null;
        }

        return [
            'type' => 'heading',
            'version' => 1,
            'data' => [
                'text' => $text,
                'level' => $this->level($data['level'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function listBlock(array $data): ?array
    {
        $items = $this->splitItems($data['items'] ?? null);

        if ($items === []) {
            return null;
        }

        return [
            'type' => 'list',
            'version' => 1,
            'data' => [
                'items' => $items,
                'ordered' => $this->truthy($data['ordered'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function imageBlock(array $data): ?array
    {
        $url = trim($this->stringValue($data['url'] ?? null));

        if ($url === '') {
            return null;
        }

        return [
            'type' => 'image',
            'version' => 1,
            'data' => [
                'url' => $url,
                'alt' => trim($this->stringValue($data['alt'] ?? null)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array{type: string, text: string, level: int, items: string, ordered: bool, url: string, alt: string}
     */
    private function rowFromBlock(array $block): array
    {
        $type = $this->normaliseType($block['type'] ?? null);
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        // Every key is filled even when the type cannot use it, so a template
        // never needs a null check and a type switch keeps the old values.
        return [
            'type' => $type,
            'text' => $this->normaliseText($type, $this->contentOf($block)),
            'level' => $this->level($data['level'] ?? $block['level'] ?? null),
            'items' => $this->formItems($block),
            'ordered' => $this->truthy($data['ordered'] ?? $block['ordered'] ?? null),
            'url' => trim($this->stringValue($data['url'] ?? $block['url'] ?? null)),
            'alt' => trim($this->stringValue($data['alt'] ?? $block['alt'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function formItems(array $block): string
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        return implode("\n", $this->splitItems($data['items'] ?? $block['items'] ?? null));
    }

    /**
     * The template nests everything under data[] because that is the stored
     * shape, but toForm() hands the template flat rows. Reading both makes the
     * pair a true inverse, so a caller can feed toForm() output straight back.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowData(array $row): array
    {
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];

        foreach (['text', 'level', 'items', 'ordered', 'url', 'alt'] as $key) {
            if (!array_key_exists($key, $data) && array_key_exists($key, $row)) {
                $data[$key] = $row[$key];
            }
        }

        return $data;
    }

    /**
     * Text is read from the same keys, in the same order, as the public
     * renderer so the editor shows the text a reader would actually see.
     *
     * @param array<string, mixed> $block
     */
    private function contentOf(array $block): string
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        if (isset($data['text']) && is_scalar($data['text'])) {
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

    /**
     * The type select posts one of types(), but a lowercase value lets the
     * match arms below stay exact, and a browser that mangles casing should
     * not lose the author's block.
     */
    private function normaliseType(mixed $type): string
    {
        if (!is_scalar($type)) {
            return 'paragraph';
        }

        $type = strtolower(trim((string) $type));

        return in_array($type, self::TYPES, true) ? $type : 'paragraph';
    }

    /**
     * Non-numeric input is a broken field, not a request for h1, so it falls
     * back to the default level rather than to the clamp floor.
     */
    private function level(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 2;
        }

        return max(1, min(6, (int) $value));
    }

    /**
     * Checkbox semantics: an unticked box is absent from POST, and a ticked one
     * posts its value attribute, which is "1" unless the template says otherwise.
     */
    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Accepts the textarea value, and also an already-split array so a
     * hand-built payload is normalised instead of silently dropped.
     *
     * @return list<string>
     */
    private function splitItems(mixed $raw): array
    {
        if (is_array($raw)) {
            $candidates = $raw;
        } elseif (is_scalar($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

            if ($lines === false) {
                return [];
            }

            $candidates = $lines;
        } else {
            return [];
        }

        $items = [];

        foreach ($candidates as $candidate) {
            $item = trim($this->stringValue($candidate));

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A leading space is never meaningful in prose, but inside code it is
     * syntax, so code keeps its indentation and only loses blank edges.
     */
    private function normaliseText(string $type, string $text): string
    {
        if ($type === 'code') {
            return rtrim(ltrim($text, "\r\n"));
        }

        return trim($text);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
