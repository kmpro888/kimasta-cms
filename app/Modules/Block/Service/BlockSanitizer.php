<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Service;

use CMS\Modules\Block\Contracts\BlockSanitizerInterface;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Block payload and HTML sanitizer.
 *
 * `sanitizeData()` keeps only the attributes a block type declares and coerces
 * their types, so an undeclared key can never reach stored content.
 *
 * `sanitizeHtml()` is a real allowlist implemented with DOM rather than regex:
 * a regex sanitizer is famously bypassable, and this is the one place authored
 * HTML re-enters the page. It drops disallowed elements entirely, strips every
 * attribute that is not explicitly allowed, and refuses dangerous URL schemes.
 */
final class BlockSanitizer implements BlockSanitizerInterface
{
    /** Elements that may survive sanitization. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'span', 'div',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'small',
        'ul', 'ol', 'li',
        'a', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    ];

    /**
     * Allowed attributes, per tag, plus `*` for attributes allowed everywhere.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        '*' => [],
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'],
        'ol' => ['start'],
    ];

    /** URL schemes permitted in href/src. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitizeData(array $data, array $schema): array
    {
        $clean = [];

        foreach ($schema as $attribute => $kind) {
            if (!array_key_exists($attribute, $data)) {
                continue;
            }

            $value = $data[$attribute];

            $clean[$attribute] = match ($kind) {
                'int' => is_numeric($value) ? (int) $value : 0,
                'bool' => (bool) $value,
                'array' => $this->sanitizeArray($value),
                default => is_scalar($value) ? (string) $value : '',
            };
        }

        return $clean;
    }

    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            // Without DOM there is no safe way to allowlist; escaping is the only
            // honest fallback.
            return $this->escapeHtml($html);
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        // A wrapper gives the parser a single root and preserves fragment order.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="cms-block-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return $this->escapeHtml($html);
        }

        $root = $document->getElementById('cms-block-root');

        if ($root === null) {
            return $this->escapeHtml($html);
        }

        $this->scrub($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    public function escapeHtml(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Depth-first scrub: remove disallowed elements and attributes in place.
     */
    private function scrub(DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        // Iterate over a snapshot: the live NodeList shifts as nodes are removed.
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);

                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Drop the element but keep its text content, so removing a
                // wrapper never loses the paragraph inside it.
                $this->unwrap($child, $node);

                continue;
            }

            $this->scrubAttributes($child, $tag);
            $this->scrub($child);
        }
    }

    private function unwrap(DOMElement $element, DOMNode $parent): void
    {
        // Script and style content is discarded outright rather than promoted.
        if (in_array(strtolower($element->nodeName), ['script', 'style', 'iframe', 'object', 'embed'], true)) {
            $parent->removeChild($element);

            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function scrubAttributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(
            self::ALLOWED_ATTRIBUTES['*'] ?? [],
            self::ALLOWED_ATTRIBUTES[$tag] ?? []
        );

        $toRemove = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (!in_array($name, $allowed, true)) {
                $toRemove[] = $attribute->nodeName;

                continue;
            }

            if (in_array($name, ['href', 'src'], true)) {
                $value = trim($attribute->nodeValue ?? '');

                if (!$this->isSafeUrl($value)) {
                    $toRemove[] = $attribute->nodeName;
                }
            }

            if ($name === 'target' && ($attribute->nodeValue ?? '') === '_blank') {
                // Force a safe rel on new-window links.
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        foreach ($toRemove as $name) {
            $element->removeAttribute($name);
        }
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Fragment and root-relative links are fine.
        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        $colon = strpos($url, ':');

        if ($colon === false) {
            return true;
        }

        $scheme = strtolower(substr($url, 0, $colon));

        // Reject obfuscated schemes such as "java\nscript:".
        if (preg_match('/[\x00-\x20]/', $scheme) === 1) {
            return false;
        }

        return in_array($scheme, self::ALLOWED_SCHEMES, true);
    }

    /**
     * @return list<mixed>
     */
    private function sanitizeArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                // Nested structures (table rows, faq items) keep scalar leaves only.
                $nested = [];

                foreach ($item as $key => $leaf) {
                    $nested[(string) $key] = is_scalar($leaf) ? $leaf : null;
                }

                $clean[] = $nested;

                continue;
            }

            if (is_scalar($item) || $item === null) {
                $clean[] = $item;
            }
        }

        return $clean;
    }
}
