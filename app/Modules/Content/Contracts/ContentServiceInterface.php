<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Contracts;

use CMS\Modules\Content\Entity\Content;

/**
 * The canonical content application service.
 *
 * Controllers, blocks and plugins talk to content through this interface only;
 * none of them may reach a repository or write SQL. Publishing state
 * transitions are owned by the workflow module (Agent 08), so the methods here
 * cover the authoring lifecycle up to and including trashing.
 *
 * FROZEN CONTRACT.
 */
interface ContentServiceInterface
{
    /**
     * @param array{
     *     type?: string,
     *     title: string,
     *     slug?: string,
     *     excerpt?: string|null,
     *     content_json?: array<int, array<string, mixed>>|string|null,
     *     author_id: int,
     *     status?: string,
     *     featured_media_id?: int|null,
     *     comment_status?: string
     * } $attributes
     */
    public function create(int $siteId, array $attributes): Content;

    /**
     * @param array{
     *     title?: string,
     *     slug?: string,
     *     excerpt?: string|null,
     *     content_json?: array<int, array<string, mixed>>|string|null,
     *     status?: string,
     *     featured_media_id?: int|null,
     *     comment_status?: string
     * } $attributes
     */
    public function update(int $id, int $siteId, array $attributes): Content;

    /**
     * @throws \CMS\Modules\Content\Exceptions\ContentNotFoundException
     */
    public function get(int $id, int $siteId): Content;

    /**
     * @throws \CMS\Modules\Content\Exceptions\ContentNotFoundException
     */
    public function getBySlug(string $slug, string $type, int $siteId): Content;

    /**
     * Move to trash. Returns false when the row does not exist on this site.
     */
    public function trash(int $id, int $siteId): bool;

    /**
     * Permanently delete. Only allowed when explicitly requested; the
     * implementation must refuse to purge content that is not already trashed.
     *
     * @throws \CMS\Modules\Content\Exceptions\ContentNotPurgeableException
     */
    public function purge(int $id, int $siteId): bool;

    /**
     * Set the editorial status. Workflow (Agent 08) drives the transition rules;
     * this is the low-level setter it uses.
     *
     * @throws \CMS\Modules\Content\Exceptions\InvalidStatusException
     */
    public function setStatus(int $id, int $siteId, string $status, ?\DateTimeImmutable $publishedAt = null): Content;
}
