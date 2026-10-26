<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Contracts;

/**
 * Sanitizes block payloads.
 *
 * Two levels on purpose:
 *  - `sanitizeData()` runs for EVERY block and keeps only the attributes the
 *    block type declares, coercing types. A compromised editor payload cannot
 *    smuggle an unexpected key into stored content.
 *  - `sanitizeHtml()` is the strict allowlist used by the `html` block. Raw HTML
 *    is a capability-gated feature, so when it is not explicitly allowed the
 *    input is escaped rather than trusted.
 *
 * FROZEN CONTRACT.
 */
interface BlockSanitizerInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $schema Allowed attribute names and types.
     *
     * @return array<string, mixed>
     */
    public function sanitizeData(array $data, array $schema): array;

    /**
     * Strict allowlist sanitizer for authored HTML.
     */
    public function sanitizeHtml(string $html): string;

    /**
     * Escape instead of trusting: used when raw HTML is not permitted.
     */
    public function escapeHtml(string $html): string;
}
