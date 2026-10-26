<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Service;

use CMS\Core\Events\DomainEvent;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;
use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\SlugServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Content\Exceptions\ContentNotPurgeableException;
use CMS\Modules\Content\Exceptions\InvalidContentDataException;
use CMS\Modules\Content\Exceptions\InvalidStatusException;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Canonical content application service.
 *
 * Everything that writes content goes through here, which is what keeps the
 * three derived representations consistent:
 *
 *   content_json  → canonical blocks
 *   content_html  → compiled once at save time, never at view time
 *   content_plain → plain projection for search/excerpt/SEO
 *
 * The service also owns the URL registry entry, so a permalink is always in step
 * with the slug. Publishing state *rules* belong to the workflow module; this
 * service only enforces that a status is a known value.
 */
final class ContentService implements ContentServiceInterface
{
    /**
     * Request-scoped read memo for `get()`, keyed `siteId:id`.
     *
     * Cleared by every write, so a request can never observe a row it just changed. It is
     * deliberately not a cross-request cache: correctness on the admin path matters more
     * than saving one query between requests, and the public path has the page cache.
     *
     * @var array<string, Content>
     */
    private array $memo = [];

    public function __construct(
        private readonly ContentRepositoryInterface $contents,
        private readonly SlugServiceInterface $slugs,
        private readonly UrlGeneratorInterface $urls,
        private readonly BlockCompilerInterface $compiler,
        private readonly ContentTypeRegistryInterface $types,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(int $siteId, array $attributes): Content
    {
        $this->memo = [];
        if ($siteId <= 0) {
            throw new InvalidContentDataException('A site id is required to create content.');
        }

        $type = $this->resolveType($attributes['type'] ?? 'post');
        $title = $this->requireTitle($attributes['title'] ?? null);
        $authorId = (int) ($attributes['author_id'] ?? 0);

        if ($authorId <= 0) {
            throw new InvalidContentDataException('An author id is required to create content.');
        }

        $status = (string) ($attributes['status'] ?? Content::STATUS_DRAFT);
        $this->assertStatus($status);

        $slug = isset($attributes['slug']) && is_string($attributes['slug']) && trim($attributes['slug']) !== ''
            ? $this->slugs->uniqueFor($attributes['slug'], $type, $siteId)
            : $this->slugs->uniqueFor($title, $type, $siteId);

        [$json, $html, $plain] = $this->compile($attributes['content_json'] ?? null);

        $publishedAt = $status === Content::STATUS_PUBLISHED
            ? $this->clock->now()->format('Y-m-d H:i:s')
            : null;

        $content = $this->contents->create($siteId, [
            'type' => $type,
            'author_id' => $authorId,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $this->normalizeExcerpt($attributes['excerpt'] ?? null, $plain),
            'content_json' => $json,
            'content_html' => $html,
            'content_plain' => $plain,
            'status' => $status,
            'featured_media_id' => $attributes['featured_media_id'] ?? null,
            'comment_status' => (string) ($attributes['comment_status'] ?? 'open'),
            'published_at' => $publishedAt,
            'scheduled_at' => $attributes['scheduled_at'] ?? null,
        ]);

        $this->syncUrl($content);

        $this->events->dispatch(DomainEvent::create(
            'content.created',
            $this->eventPayload($content),
            $siteId,
            $this->clock,
        ));

        return $content;
    }

    public function update(int $id, int $siteId, array $attributes): Content
    {
        $this->memo = [];
        $existing = $this->contents->findById($id, $siteId);

        if ($existing === null) {
            throw new ContentNotFoundException();
        }

        $changes = [];

        if (array_key_exists('title', $attributes)) {
            $changes['title'] = $this->requireTitle($attributes['title']);
        }

        if (array_key_exists('status', $attributes)) {
            $status = (string) $attributes['status'];
            $this->assertStatus($status);
            $changes['status'] = $status;

            if ($status === Content::STATUS_PUBLISHED && $existing->publishedAt === null) {
                $changes['published_at'] = $this->clock->now()->format('Y-m-d H:i:s');
            }
        }

        if (array_key_exists('content_json', $attributes)) {
            [$json, $html, $plain] = $this->compile($attributes['content_json']);
            $changes['content_json'] = $json;
            $changes['content_html'] = $html;
            $changes['content_plain'] = $plain;

            if (!array_key_exists('excerpt', $attributes)) {
                // Keep a generated excerpt in step with the body unless the
                // author supplied one explicitly.
                $changes['excerpt'] = $this->normalizeExcerpt($existing->excerpt, $plain);
            }
        }

        if (array_key_exists('excerpt', $attributes)) {
            $changes['excerpt'] = $this->normalizeExcerpt(
                $attributes['excerpt'],
                $changes['content_plain'] ?? $existing->contentPlain
            );
        }

        if (array_key_exists('featured_media_id', $attributes)) {
            $changes['featured_media_id'] = $attributes['featured_media_id'];
        }

        if (array_key_exists('comment_status', $attributes)) {
            $changes['comment_status'] = (string) $attributes['comment_status'];
        }

        // Scheduling is a content field, so the editorial workflow sets it
        // through this method rather than reaching into the table itself.
        if (array_key_exists('scheduled_at', $attributes)) {
            $scheduledAt = $attributes['scheduled_at'];

            $changes['scheduled_at'] = $scheduledAt instanceof DateTimeImmutable
                ? $scheduledAt->format('Y-m-d H:i:s')
                : (is_string($scheduledAt) && $scheduledAt !== '' ? $scheduledAt : null);
        }

        // A slug change must be resolved for uniqueness and the URL registry
        // updated, otherwise the old path would keep serving the entity.
        $slugChanged = false;

        if (array_key_exists('slug', $attributes) && is_string($attributes['slug']) && trim($attributes['slug']) !== '') {
            $changes['slug'] = $this->slugs->uniqueFor($attributes['slug'], $existing->type, $siteId, $id);
            $slugChanged = $changes['slug'] !== $existing->slug;
        } elseif (array_key_exists('title', $attributes) && $existing->slug === '') {
            $changes['slug'] = $this->slugs->uniqueFor($changes['title'], $existing->type, $siteId, $id);
            $slugChanged = true;
        }

        if ($changes === []) {
            return $existing;
        }

        $this->contents->update($id, $siteId, $changes);

        $updated = $this->contents->findById($id, $siteId);

        if ($updated === null) {
            throw new ContentNotFoundException();
        }

        if ($slugChanged) {
            // Replace the mapping: leaving the old path behind would keep it
            // resolving this entity.
            $this->syncUrl($updated, true);
        }

        $this->events->dispatch(DomainEvent::create(
            'content.updated',
            $this->eventPayload($updated),
            $siteId,
            $this->clock,
        ));

        return $updated;
    }

    public function get(int $id, int $siteId): Content
    {
        // Request-scoped memo. A single public page resolves the same row several times
        // (the controller, SEO, breadcrumbs), and each of those cost a full query. The
        // memo is cleared by every write below, so a request never reads a stale row it
        // just changed.
        $key = $siteId . ':' . $id;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $content = $id > 0 ? $this->contents->findById($id, $siteId) : null;

        if ($content === null) {
            throw new ContentNotFoundException();
        }

        return $this->memo[$key] = $content;
    }

    public function getBySlug(string $slug, string $type, int $siteId): Content
    {
        $content = $this->contents->findBySlug($slug, $type, $siteId);

        if ($content === null) {
            throw new ContentNotFoundException();
        }

        return $content;
    }

    public function trash(int $id, int $siteId): bool
    {
        $this->memo = [];
        $content = $this->contents->findById($id, $siteId);

        if ($content === null) {
            return false;
        }

        $trashed = $this->contents->trash($id, $siteId, $this->clock->now());

        if ($trashed) {
            // The permalink must stop resolving the moment content leaves the
            // public site, so cache invalidation can act on a precise path.
            $this->urls->forget($siteId, 'content', $id);

            $this->events->dispatch(DomainEvent::create(
                'content.deleted',
                $this->eventPayload($content),
                $siteId,
                $this->clock,
            ));
        }

        return $trashed;
    }

    public function purge(int $id, int $siteId): bool
    {
        $this->memo = [];
        $content = $this->contents->findById($id, $siteId);

        if ($content === null) {
            return false;
        }

        if (!$content->isTrashed()) {
            throw new ContentNotPurgeableException();
        }

        $this->urls->forget($siteId, 'content', $id);
        $purged = $this->contents->purge($id, $siteId);

        if ($purged) {
            $this->events->dispatch(DomainEvent::create(
                'content.deleted',
                $this->eventPayload($content),
                $siteId,
                $this->clock,
            ));
        }

        return $purged;
    }

    public function setStatus(int $id, int $siteId, string $status, ?DateTimeImmutable $publishedAt = null): Content
    {
        $this->memo = [];
        $this->assertStatus($status);

        $content = $this->contents->findById($id, $siteId);

        if ($content === null) {
            throw new ContentNotFoundException();
        }

        $changes = ['status' => $status];

        if ($status === Content::STATUS_PUBLISHED) {
            $changes['published_at'] = ($publishedAt ?? $this->clock->now())
                ->format('Y-m-d H:i:s');
        }

        $this->contents->update($id, $siteId, $changes);

        $updated = $this->contents->findById($id, $siteId);

        if ($updated === null) {
            throw new ContentNotFoundException();
        }

        if ($status === Content::STATUS_PUBLISHED) {
            $this->syncUrl($updated);
        } elseif ($status === Content::STATUS_TRASH) {
            $this->urls->forget($siteId, 'content', $id);
        }

        $this->events->dispatch(DomainEvent::create(
            'content.updated',
            $this->eventPayload($updated),
            $siteId,
            $this->clock,
        ));

        return $updated;
    }

    /**
     * Ensure the URL registry points at this content row's current path.
     *
     * `$replace` is used on a slug change: the previous mapping must be removed
     * or the old permalink would keep resolving the entity and could later
     * collide with a new one.
     */
    private function syncUrl(Content $content, bool $replace = false): void
    {
        $id = $content->id;

        if ($id === null) {
            return;
        }

        if ($replace) {
            $this->urls->forget($content->siteId, 'content', $id);
        }

        $path = $this->urls->pathFor($content->type, $content->slug);
        $this->urls->register($content->siteId, $path, 'content', $id);
    }

    private function resolveType(mixed $type): string
    {
        $key = is_string($type) && trim($type) !== '' ? trim($type) : 'post';

        if (!$this->types->has($key)) {
            throw new InvalidArgumentException('Unknown content type: ' . $key);
        }

        return $key;
    }

    private function requireTitle(mixed $title): string
    {
        if (!is_string($title) || trim($title) === '') {
            throw new InvalidContentDataException('A title is required.');
        }

        return trim($title);
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, Content::statuses(), true)) {
            throw new InvalidStatusException($status);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function compile(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, null, null];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                throw new InvalidContentDataException('content_json must be valid JSON.');
            }

            $blocks = $decoded;
        } elseif (is_array($raw)) {
            $blocks = $raw;
        } else {
            throw new InvalidContentDataException('content_json must be an array or a JSON string.');
        }

        return [
            json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->compiler->toHtml($blocks),
            $this->compiler->toPlainText($blocks),
        ];
    }

    private function normalizeExcerpt(mixed $excerpt, ?string $plain): ?string
    {
        if (is_string($excerpt) && trim($excerpt) !== '') {
            return trim($excerpt);
        }

        if ($plain === null || $plain === '') {
            return null;
        }

        // Derive from the plain projection so an excerpt never contains markup.
        $trimmed = trim($plain);

        return strlen($trimmed) > 200 ? rtrim(substr($trimmed, 0, 200)) . '…' : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Content $content): array
    {
        return [
            'id' => $content->id,
            'site_id' => $content->siteId,
            'type' => $content->type,
            'slug' => $content->slug,
            'status' => $content->status,
            'author_id' => $content->authorId,
            'path' => $this->urls->pathFor($content->type, $content->slug),
        ];
    }
}
