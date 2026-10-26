<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

/**
 * Compiles canonical block JSON into renderable HTML and searchable text.
 *
 * The block engine itself is Agent 11. Content only needs the contract at save
 * time, so this interface is the seam: Content calls it, Agent 11 replaces the
 * default implementation with the real block renderer.
 *
 * FROZEN CONTRACT.
 */
interface BlockCompilerInterface
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function toHtml(array $blocks): string;

    /**
     * Plain-text projection used for search, excerpt generation and SEO
     * analysis, so those never have to strip HTML at query time.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public function toPlainText(array $blocks): string;
}
