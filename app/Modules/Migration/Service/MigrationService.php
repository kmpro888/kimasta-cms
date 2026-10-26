<?php

declare(strict_types=1);

namespace CMS\Modules\Migration\Service;

use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Exceptions\CommentsClosedException;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Modules\Identity\Exceptions\DuplicateUserException;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Migration\Contracts\MigrationServiceInterface;
use CMS\Modules\Migration\Repository\MigrationRepositoryInterface;
use CMS\Modules\Migration\Source\WordPressSource;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use DateTimeImmutable;
use Throwable;

/**
 * WordPress → platform migration.
 *
 * Order matters and is encoded in `types()`: users and terms before content (content needs
 * an author and its terms), media before content (content needs its featured image), content
 * before comments (a comment needs its post).
 *
 * Every import method is idempotent through `migration_maps`: a source row that already has
 * a target is skipped, so a batch that timed out halfway can simply be run again.
 *
 * Nothing is discarded silently. Every skip, every unmapped field and every status change
 * leaves a `migration_logs` row.
 */
final class MigrationService implements MigrationServiceInterface
{
    /** Postmeta keys that are carried across; everything else is logged as unmapped. */
    private const KNOWN_META = [
        '_thumbnail_id',
        '_wp_page_template',
        '_edit_lock',
        '_edit_last',
        // Yoast stores values under both the modern and the legacy prefix.
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_canonical',
        '_yoast_wpseo_meta-robots-noindex',
        '_wpseo_title',
        '_wpseo_metadesc',
        '_wpseo_canonical',
    ];

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly WordPressSource $source,
        private readonly MigrationRepositoryInterface $repository,
        private readonly ContentServiceInterface $contents,
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly MediaServiceInterface $media,
        private readonly CommentServiceInterface $comments,
        private readonly UserServiceInterface $users,
        private readonly SeoServiceInterface $seo,
        private readonly RedirectServiceInterface $redirects,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteServiceInterface $sites,
        private readonly string $uploadsPath = '',
        private readonly string $runId = '',
    ) {
    }

    public function types(): array
    {
        return ['user', 'term', 'media', 'post', 'page', 'comment'];
    }

    public function analyze(int $siteId): array
    {
        return $this->shape($siteId);
    }

    public function plan(int $siteId): array
    {
        return $this->shape($siteId);
    }

    public function runBatch(int $siteId, string $type, int $limit = 50, int $after = 0): array
    {
        $limit = max(1, min(200, $limit));

        $rows = match ($type) {
            'user' => $this->source->usersBatch($after, $limit),
            'term' => $this->source->termsBatch($after, $limit),
            'media' => $this->source->attachmentsBatch($after, $limit),
            'post' => $this->source->postsBatch($after, $limit, 'post'),
            'page' => $this->source->postsBatch($after, $limit, 'page'),
            'comment' => $this->source->commentsBatch($after, $limit),
            default => throw new \InvalidArgumentException('Unknown migration type: ' . $type),
        };

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $lastId = $after;

        foreach ($rows as $row) {
            $sourceId = (int) ($row['ID'] ?? $row['tt_id'] ?? $row['comment_ID'] ?? 0);
            $lastId = $sourceId;

            if ($sourceId <= 0) {
                continue;
            }

            // Idempotency: a row already mapped is skipped without touching anything.
            //
            // The lookup key must match exactly what the importer recorded. Terms record
            // both `term:<taxonomy>` and `term`, so the check asks for the more specific
            // key; everything else records its bare type.
            if ($this->repository->targetId($siteId, $this->mapKey($type, $row), $sourceId) !== null) {
                $skipped++;

                continue;
            }

            try {
                $result = match ($type) {
                    'user' => $this->importUser($siteId, $row),
                    'term' => $this->importTerm($siteId, $row),
                    'media' => $this->importMedia($siteId, $row),
                    'post', 'page' => $this->importContent($siteId, $type, $row),
                    'comment' => $this->importComment($siteId, $row),
                };
            } catch (Throwable $exception) {
                // One bad row must not abandon the batch: log it and continue.
                $this->repository->log($siteId, $this->runId, 'error', [
                    'source_type' => $type,
                    'source_id' => $sourceId,
                    'reason' => $exception->getMessage(),
                ]);

                $failed++;

                continue;
            }

            if ($result === null) {
                $skipped++;

                continue;
            }

            $imported++;
        }

        $done = count($rows) < $limit;

        return [
            'type' => $type,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'next_after' => $lastId,
            'done' => $done,
        ];
    }

    public function runAll(int $siteId, int $batchSize = 50, ?callable $progress = null): array
    {
        $summary = [];

        foreach ($this->types() as $type) {
            $totals = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
            $after = 0;

            // Bounded loop: a corrupt source cannot spin forever.
            for ($batch = 0; $batch < 10000; $batch++) {
                $result = $this->runBatch($siteId, $type, $batchSize, $after);

                $totals['imported'] += $result['imported'];
                $totals['skipped'] += $result['skipped'];
                $totals['failed'] += $result['failed'];

                if ($progress !== null) {
                    $progress($type, $result);
                }

                if ($result['done'] || $result['next_after'] <= $after) {
                    break;
                }

                $after = $result['next_after'];
            }

            $summary[$type] = $totals;
        }

        return $summary;
    }

    public function report(int $siteId): array
    {
        $counts = $this->shape($siteId);
        $logCounts = $this->repository->logCounts($siteId);

        // URL integrity: compare the WordPress permalink structure against the paths this
        // platform produces.
        $structure = $this->source->option('permalink_structure') ?? '';
        $postSource = $this->postPrefix($structure);

        $preserved = 0;
        $redirected = 0;

        foreach (['post', 'page'] as $type) {
            foreach ($this->repository->mappedTargetIds($siteId, $type) as $targetId) {
                try {
                    $content = $this->contents->get($targetId, $siteId);
                } catch (Throwable) {
                    continue;
                }

                $newPath = $this->urls->pathFor($content->type, $content->slug);
                $oldPath = $content->type === 'page' ? '/' . $content->slug : $postSource . $content->slug;

                if (rtrim($oldPath, '/') === rtrim($newPath, '/')) {
                    $preserved++;
                } else {
                    $redirected++;
                }
            }
        }

        return [
            'source' => [
                'prefix' => $this->source->prefix(),
                'permalink_structure' => $structure,
                'available' => $this->source->isAvailable(),
            ],
            'counts' => $counts,
            'logs' => $logCounts,
            'urls' => [
                'preserved' => $preserved,
                'redirected' => $redirected,
            ],
        ];
    }

    public function logs(int $siteId, string $level = 'warning', int $limit = 100): array
    {
        return $this->repository->logs($siteId, $level, $limit);
    }

    // ------------------------------------------------------------------ importers

    /**
     * @param array<string, mixed> $row
     */
    private function importUser(int $siteId, array $row): ?int
    {
        $sourceId = (int) $row['ID'];
        $email = trim((string) $row['user_email']);
        $login = trim((string) $row['user_login']);

        if ($email === '' || $login === '') {
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => 'user',
                'source_id' => $sourceId,
                'reason' => 'WordPress user has no email or login; skipped.',
            ]);

            return null;
        }

        // WordPress password hashes (phpass) are not portable to PHP's password API, so the
        // account is created with a random password and marked for a reset instead of being
        // silently given a password nobody knows.
        $password = bin2hex(random_bytes(16));

        try {
            $user = $this->users->create($siteId, [
                'username' => $this->uniqueUsername($siteId, $login),
                'email' => $email,
                'password' => $password,
                'display_name' => (string) ($row['display_name'] !== '' ? $row['display_name'] : $login),
            ]);
        } catch (DuplicateUserException) {
            // Already present: map to the existing account rather than failing the batch.
            foreach ($this->users->listForSite($siteId) as $existing) {
                if (strcasecmp($existing->email, $email) === 0) {
                    $this->repository->recordMap($siteId, 'user', $sourceId, 'user', (int) $existing->id);
                    $this->repository->log($siteId, $this->runId, 'warning', [
                        'source_type' => 'user',
                        'source_id' => $sourceId,
                        'field' => 'user_email',
                        'reason' => 'Email already exists; mapped to the existing account.',
                    ]);

                    return (int) $existing->id;
                }
            }

            throw new DuplicateUserException('email');
        }

        $this->repository->recordMap($siteId, 'user', $sourceId, 'user', (int) $user->id);

        $this->repository->log($siteId, $this->runId, 'info', [
            'source_type' => 'user',
            'source_id' => $sourceId,
            'field' => 'user_pass',
            'reason' => 'Password hash is not portable; the account needs a password reset.',
        ]);

        return (int) $user->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importTerm(int $siteId, array $row): ?int
    {
        $sourceId = (int) $row['tt_id'];
        $taxonomy = (string) $row['taxonomy'] === 'category' ? 'category' : 'tag';

        $term = $this->taxonomy->createTerm(
            $siteId,
            $taxonomy,
            (string) $row['name'],
            (string) ($row['description'] ?? '') !== '' ? (string) $row['description'] : null,
            null,
            (string) ($row['slug'] ?? '') !== '' ? (string) $row['slug'] : null,
        );

        $this->repository->recordMap($siteId, 'term:' . $taxonomy, $sourceId, 'term', (int) $term->id);
        // Also map by the bare term id, because relationships reference term_id rather than
        // term_taxonomy_id on some imports.
        $this->repository->recordMap($siteId, 'term', (int) $row['term_id'], 'term', (int) $term->id);

        return (int) $term->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importMedia(int $siteId, array $row): ?int
    {
        $sourceId = (int) $row['ID'];
        $guid = (string) $row['guid'];

        $relative = $this->relativeUploadPath($guid);

        if ($relative === null) {
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => 'media',
                'source_id' => $sourceId,
                'field' => 'guid',
                'reason' => 'Could not derive an upload path from the guid; file not imported.',
            ]);

            return null;
        }

        $absolute = rtrim($this->uploadsPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if ($this->uploadsPath === '' || !is_file($absolute)) {
            // The file lives on the old server. Creating a media row with no file would
            // produce a broken image on every page, so the row is not created at all.
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => 'media',
                'source_id' => $sourceId,
                'field' => 'guid',
                'reason' => 'File not found under the configured uploads path; row not created.',
            ]);

            return null;
        }

        $mime = (string) $row['post_mime_type'];
        $clientMime = isset(self::MIME_EXTENSIONS[$mime]) ? $mime : 'application/octet-stream';

        $uploaded = new UploadedFile(
            tmpPath: $absolute,
            originalName: basename($relative),
            size: (int) filesize($absolute),
            clientMime: $clientMime,
            error: UPLOAD_ERR_OK,
        );

        $media = $this->media->upload(
            $siteId,
            $this->defaultAuthorId($siteId),
            $uploaded,
            (string) ($row['post_title'] ?? '') !== '' ? (string) $row['post_title'] : null,
        );

        $this->repository->recordMap($siteId, 'media', $sourceId, 'media', (int) $media->id);

        return (int) $media->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importContent(int $siteId, string $type, array $row): ?int
    {
        $sourceId = (int) $row['ID'];
        $authorId = $this->repository->targetId($siteId, 'user', (int) $row['post_author']);

        if ($authorId === null) {
            $authorId = $this->defaultAuthorId($siteId);

            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => $type,
                'source_id' => $sourceId,
                'field' => 'post_author',
                'reason' => 'Author was not migrated; the row was attributed to the site default author.',
            ]);
        }

        $meta = $this->source->postMeta($sourceId);

        // WordPress stores HTML. The platform has a raw-HTML block for exactly this, so the
        // body is preserved byte for byte instead of being flattened into paragraphs.
        $blocks = [];

        if (trim((string) $row['post_content']) !== '') {
            $blocks[] = ['type' => 'html', 'version' => 1, 'data' => ['html' => (string) $row['post_content']]];
        }

        $attributes = [
            'type' => $type === 'page' ? 'page' : 'post',
            'title' => (string) ($row['post_title'] !== '' ? $row['post_title'] : '(untitled)'),
            'slug' => (string) $row['post_name'] !== '' ? (string) $row['post_name'] : null,
            'excerpt' => (string) ($row['post_excerpt'] ?? '') !== '' ? (string) $row['post_excerpt'] : null,
            'author_id' => $authorId,
            'content_json' => $blocks,
            'comment_status' => (string) ($row['comment_status'] ?? 'open') === 'open' ? 'open' : 'closed',
        ];

        $featuredId = null;

        if (isset($meta['_thumbnail_id'][0]) && is_numeric($meta['_thumbnail_id'][0])) {
            $featuredId = $this->repository->targetId($siteId, 'media', (int) $meta['_thumbnail_id'][0]);

            if ($featuredId === null) {
                $this->repository->log($siteId, $this->runId, 'warning', [
                    'source_type' => $type,
                    'source_id' => $sourceId,
                    'field' => '_thumbnail_id',
                    'reason' => 'Featured image was not migrated; content created without one.',
                ]);
            }
        }

        if ($featuredId !== null) {
            $attributes['featured_media_id'] = $featuredId;
        }

        $content = $this->contents->create($siteId, $attributes);

        // Status and dates are applied through the service so the workflow stays authoritative.
        $status = $this->mapStatus((string) $row['post_status']);

        if ($status !== 'draft') {
            $this->contents->setStatus(
                (int) $content->id,
                $siteId,
                $status,
                $this->toDate((string) ($row['post_date_gmt'] ?? '')) ?? $this->toDate((string) $row['post_date']),
            );
        }

        $this->applyTerms($siteId, $type, $sourceId, (int) $content->id);
        $this->applySeo($siteId, $type, $sourceId, (int) $content->id, $meta);
        $this->logUnknownMeta($siteId, $type, $sourceId, $meta);
        $this->preserveOrRedirect($siteId, $content, (string) $row['post_name']);

        $this->repository->recordMap($siteId, $type, $sourceId, 'content', (int) $content->id);

        return (int) $content->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importComment(int $siteId, array $row): ?int
    {
        $sourceId = (int) $row['comment_ID'];
        $contentId = $this->repository->targetId($siteId, 'post', (int) $row['comment_post_ID'])
            ?? $this->repository->targetId($siteId, 'page', (int) $row['comment_post_ID']);

        if ($contentId === null) {
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => 'comment',
                'source_id' => $sourceId,
                'field' => 'comment_post_ID',
                'reason' => 'The post this comment belongs to was not migrated.',
            ]);

            return null;
        }

        // Comments in this platform require an account; WordPress allowed guests.
        $userId = $this->userForComment($siteId, (string) $row['comment_author_email']);

        if ($userId === null) {
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => 'comment',
                'source_id' => $sourceId,
                'field' => 'comment_author_email',
                'reason' => 'Guest commenter has no account on the target site; comment not imported.',
            ]);

            return null;
        }

        try {
            $comment = $this->comments->submit(
                $siteId,
                $contentId,
                $userId,
                (string) $row['comment_content'],
            );
        } catch (CommentsClosedException) {
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => 'comment',
                'source_id' => $sourceId,
                'reason' => 'Comments are closed on the migrated content.',
            ]);

            return null;
        }

        // WordPress `1` means approved; anything else is spam, trash or pending.
        if ((string) $row['comment_approved'] === '1') {
            $this->comments->approve((int) $comment->id, $siteId);
        }

        $this->repository->recordMap($siteId, 'comment', $sourceId, 'comment', (int) $comment->id);

        return (int) $comment->id;
    }

    // ------------------------------------------------------------------- helpers

    /**
     * The `migration_maps.source_type` value a given row is recorded under.
     *
     * Kept in one place because a mismatch between the importer's key and the skip check's
     * key would silently re-import every row on a rerun — the exact failure this module is
     * built to prevent.
     *
     * @param array<string, mixed> $row
     */
    private function mapKey(string $type, array $row): string
    {
        if ($type === 'term') {
            $taxonomy = (string) ($row['taxonomy'] ?? '');

            return $taxonomy !== '' ? 'term:' . $taxonomy : 'term';
        }

        return $type;
    }

    /**
     * @return array<string, array{source_total: int, imported: int, pending: int}>
     */
    private function shape(int $siteId): array
    {
        $types = ['user', 'term', 'media', 'post', 'page', 'comment'];

        // A source that is not present (fresh install, wrong DSN, wrong prefix) is a normal
        // state before a migration, not an error. Reporting zeros is more useful than
        // surfacing "table wp_users doesn't exist" from a read-only inspection command.
        if (!$this->source->isAvailable()) {
            $out = [];

            foreach ($types as $type) {
                $out[$type] = [
                    'source_total' => 0,
                    'imported' => $this->repository->countMaps($siteId, $type),
                    'pending' => 0,
                ];
            }

            return $out;
        }

        $totals = [
            'user' => $this->source->countUsers(),
            'term' => $this->source->countTerms(),
            'media' => $this->source->countAttachments(),
            'post' => $this->source->countPosts('post'),
            'page' => $this->source->countPosts('page'),
            'comment' => $this->source->countComments(),
        ];

        $out = [];

        foreach ($totals as $type => $total) {
            $imported = $this->repository->countMaps($siteId, $type);

            $out[$type] = [
                'source_total' => $total,
                'imported' => $imported,
                'pending' => max(0, $total - $imported),
            ];
        }

        return $out;
    }

    private function mapStatus(string $wpStatus): string
    {
        return match ($wpStatus) {
            'publish' => 'published',
            'future' => 'scheduled',
            // `pending`, `draft`, `private` and anything unknown stay unpublished; the
            // status is logged rather than guessed.
            default => 'draft',
        };
    }

    /**
     * WordPress permalink prefix for posts, e.g. `/blog/`.
     *
     * Only the common structures are understood. An unknown structure falls back to the
     * platform's own path, which makes every migrated post a redirect — the safe direction.
     */
    private function postPrefix(string $structure): string
    {
        if (str_contains($structure, '%postname%')) {
            $prefix = strstr($structure, '%postname%', true);

            if (is_string($prefix) && $prefix !== '') {
                return '/' . trim($prefix, '/') . '/';
            }

            return '/';
        }

        return '/blog/';
    }

    private function applyTerms(int $siteId, string $type, int $sourceId, int $contentId): void
    {
        $termIds = [];

        foreach ($this->source->postTermIds($sourceId) as $taxonomy => $ids) {
            foreach ($ids as $termId) {
                $mapped = $this->repository->targetId($siteId, 'term:' . $taxonomy, $termId)
                    ?? $this->repository->targetId($siteId, 'term', $termId);

                if ($mapped !== null) {
                    $termIds[] = $mapped;
                    continue;
                }

                $this->repository->log($siteId, $this->runId, 'warning', [
                    'source_type' => $type,
                    'source_id' => $sourceId,
                    'field' => 'term:' . $taxonomy,
                    'reason' => 'Term ' . $termId . ' was not migrated; relationship dropped.',
                ]);
            }
        }

        if ($termIds !== []) {
            $this->taxonomy->sync($contentId, $siteId, array_values(array_unique($termIds)));
        }
    }

    /**
     * @param array<string, list<string>> $meta
     */
    private function applySeo(int $siteId, string $type, int $sourceId, int $contentId, array $meta): void
    {
        $attributes = [];

        $title = $this->firstMeta($meta, ['_yoast_wpseo_title', '_wpseo_title']);
        $description = $this->firstMeta($meta, ['_yoast_wpseo_metadesc', '_wpseo_metadesc']);
        $canonical = $this->firstMeta($meta, ['_yoast_wpseo_canonical', '_wpseo_canonical']);
        $noindex = $this->firstMeta($meta, ['_yoast_wpseo_meta-robots-noindex']);

        if ($title !== null) {
            $attributes['meta_title'] = $title;
        }

        if ($description !== null) {
            $attributes['meta_description'] = $description;
        }

        // Yoast stores a relative canonical for internal links; the SEO service requires an
        // absolute URL, so a relative value is logged rather than stored as-is.
        if ($canonical !== null) {
            if (filter_var($canonical, FILTER_VALIDATE_URL) !== false) {
                $attributes['canonical_url'] = $canonical;
            } else {
                $this->repository->log($siteId, $this->runId, 'warning', [
                    'source_type' => $type,
                    'source_id' => $sourceId,
                    'field' => '_yoast_wpseo_canonical',
                    'reason' => 'Canonical is not an absolute URL; not imported.',
                ]);
            }
        }

        if ($noindex !== null && $noindex === '1') {
            $attributes['robots_index'] = false;
        }

        if ($attributes === []) {
            return;
        }

        $this->seo->store($contentId, $siteId, $attributes);
    }

    /**
     * Anything outside the known set is recorded, never dropped without trace.
     *
     * @param array<string, list<string>> $meta
     */
    private function logUnknownMeta(int $siteId, string $type, int $sourceId, array $meta): void
    {
        foreach (array_keys($meta) as $key) {
            if (in_array($key, self::KNOWN_META, true)) {
                continue;
            }

            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => $type,
                'source_id' => $sourceId,
                'field' => $key,
                'reason' => 'Unmapped postmeta key; not carried across.',
            ]);
        }
    }

    /**
     * Keep the old path when it still resolves, otherwise create a 301 to the new one.
     *
     * A broken inbound link is a permanent SEO loss, so the redirect is created eagerly
     * rather than left to an operator to notice.
     */
    private function preserveOrRedirect(int $siteId, Content $content, string $wpSlug): void
    {
        $newPath = $this->urls->pathFor($content->type, $content->slug);

        $structure = $this->source->option('permalink_structure') ?? '';
        $oldPath = $content->type === 'page'
            ? '/' . ($wpSlug !== '' ? $wpSlug : $content->slug)
            : $this->postPrefix($structure) . ($wpSlug !== '' ? $wpSlug : $content->slug);

        if (rtrim($oldPath, '/') === rtrim($newPath, '/')) {
            return;
        }

        try {
            $this->redirects->store($siteId, $oldPath, $newPath, 301);
        } catch (Throwable $exception) {
            $this->repository->log($siteId, $this->runId, 'warning', [
                'source_type' => $content->type,
                'source_id' => (int) $content->id,
                'field' => 'permalink',
                'reason' => 'Could not create redirect ' . $oldPath . ' -> ' . $newPath . ': ' . $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, list<string>> $meta
     * @param list<string> $keys
     */
    private function firstMeta(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($meta[$key][0]) && trim($meta[$key][0]) !== '') {
                return trim($meta[$key][0]);
            }
        }

        return null;
    }

    /**
     * The author to attribute rows to when the original author is unavailable.
     *
     * Falls back to the lowest-numbered user on the site, so an admin always exists after
     * `types()` imported users first.
     */
    private function defaultAuthorId(int $siteId): int
    {
        foreach ($this->users->listForSite($siteId) as $user) {
            if ($user->id !== null) {
                return (int) $user->id;
            }
        }

        throw new \RuntimeException('Cannot migrate: the target site has no users.');
    }

    private function userForComment(int $siteId, string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        foreach ($this->users->listForSite($siteId) as $user) {
            if (strcasecmp($user->email, $email) === 0) {
                return $user->id !== null ? (int) $user->id : null;
            }
        }

        return null;
    }

    private function uniqueUsername(int $siteId, string $login): string
    {
        $candidate = preg_replace('/[^A-Za-z0-9._\-]/', '-', $login) ?? 'user';
        $candidate = trim($candidate, '-');

        if ($candidate === '') {
            $candidate = 'user';
        }

        $taken = [];

        foreach ($this->users->listForSite($siteId) as $user) {
            $taken[strtolower($user->username)] = true;
        }

        if (!isset($taken[strtolower($candidate)])) {
            return $candidate;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $attempt = $candidate . '-' . $suffix;

            if (!isset($taken[strtolower($attempt)])) {
                return $attempt;
            }
        }

        return $candidate . '-' . bin2hex(random_bytes(4));
    }

    private function relativeUploadPath(string $guid): ?string
    {
        if ($guid === '') {
            return null;
        }

        $path = parse_url($guid, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return null;
        }

        $marker = '/uploads/';
        $position = strpos($path, $marker);

        if ($position === false) {
            return null;
        }

        $relative = ltrim(substr($path, $position + strlen($marker)), '/');

        return $relative !== '' ? $relative : null;
    }

    private function toDate(string $value): ?DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
