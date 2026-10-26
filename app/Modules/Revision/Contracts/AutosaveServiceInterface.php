<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Contracts;

/**
 * Per-user, per-content draft autosave.
 *
 * Autosave is deliberately NOT a revision: it fires every few seconds while
 * someone types, so writing history on each call would both waste rows and
 * evict the revisions an editor actually cares about.
 *
 * There is exactly one live autosave per (content, user), so repeated saves
 * replace it.
 *
 * FROZEN CONTRACT.
 */
interface AutosaveServiceInterface
{
    /**
     * Store or replace the autosave for this user on this content row.
     *
     * Autosave must never modify the content row itself.
     *
     * @param array<int, array<string, mixed>>|string $blocks
     */
    public function save(int $contentId, int $userId, int $siteId, array|string $blocks): void;

    /**
     * The stored payload, decoded, or null when there is none.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function get(int $contentId, int $userId, int $siteId): ?array;

    /**
     * When the autosave was last written, or null when there is none.
     */
    public function savedAt(int $contentId, int $userId, int $siteId): ?\DateTimeImmutable;

    /**
     * Discard this user's autosave (e.g. after a successful save).
     */
    public function clear(int $contentId, int $userId, int $siteId): void;
}
