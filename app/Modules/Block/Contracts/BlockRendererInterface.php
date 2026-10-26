<?php

declare(strict_types=1);

namespace CMS\Modules\Block\Contracts;

/**
 * Renders a block document.
 *
 * The hard rule from the task pack: the renderer MUST NOT query the database.
 * Anything dynamic (related posts, ad slots, embeds that need lookup) is resolved
 * *before* rendering and passed in as `$resolved`, keyed by block index. That
 * keeps a rendered page reproducible from its inputs and lets the caller decide
 * what is worth caching.
 *
 * FROZEN CONTRACT.
 */
interface BlockRendererInterface
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, mixed> $resolved Prepared data keyed by block index.
     *
     * @return array{html: string, plain: string}
     */
    public function render(array $blocks, array $resolved = []): array;

    /**
     * Indices of blocks that need preparation before they can render.
     *
     * @param array<int, array<string, mixed>> $blocks
     *
     * @return list<int>
     */
    public function dynamicBlockIndices(array $blocks): array;
}
