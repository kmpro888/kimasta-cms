<?php

declare(strict_types=1);

namespace CMS\Modules\Revision\Service;

use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Revision\Entity\Revision;
use CMS\Modules\Revision\Exceptions\ContentMissingException;
use CMS\Modules\Revision\Exceptions\RevisionNotFoundException;
use CMS\Modules\Revision\Repository\RevisionRepositoryInterface;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Revision history with a hard cap.
 *
 * "Maximum 3 revisions per content row" is enforced after every insert, so the
 * invariant holds no matter how many times content is updated. Ten updates leave
 * three revisions, not ten.
 *
 * Revision reads and writes go through the Content contract, never through the
 * `contents` table: this module owns only `content_revisions` and
 * `content_autosaves`.
 */
final class RevisionService implements RevisionServiceInterface
{
    public function __construct(
        private readonly RevisionRepositoryInterface $revisions,
        private readonly ContentServiceInterface $contents,
        private readonly ClockInterface $clock,
    ) {
    }

    public function snapshot(int $contentId, int $siteId, int $createdBy): Revision
    {
        try {
            $content = $this->contents->get($contentId, $siteId);
        } catch (ContentNotFoundException) {
            throw new ContentMissingException();
        }

        $revision = $this->revisions->create($siteId, $contentId, [
            'created_by' => $createdBy,
            'title' => $content->title,
            'excerpt' => $content->excerpt,
            'content_json' => $content->contentJson,
            'content_html' => $content->contentHtml,
            'revision_number' => $this->revisions->nextRevisionNumber($contentId, $siteId),
        ]);

        // Enforce the cap immediately: the invariant must hold after every call,
        // not eventually.
        $this->revisions->pruneOldest($contentId, $siteId, self::MAX_PER_CONTENT);

        return $revision;
    }

    public function listFor(int $contentId, int $siteId): array
    {
        return $this->revisions->listFor($contentId, $siteId);
    }

    public function get(int $revisionId, int $siteId): Revision
    {
        $revision = $revisionId > 0 ? $this->revisions->findById($revisionId, $siteId) : null;

        if ($revision === null) {
            throw new RevisionNotFoundException();
        }

        return $revision;
    }

    /**
     * Compare a revision with the one before it, or with a revision the caller names.
     *
     * Block-level rather than character-level: the editor stores blocks, and a paragraph that lost a
     * comma is not a different paragraph. Two changes matter to a person reviewing a revision — what
     * the text said, and where the text went — and this reports exactly those.
     *
     * @return array{
     *     title: array{from: string, to: string},
     *     excerpt: array{from: string, to: string},
     *     blocks: list<array{index: int, status: string, from: string, to: string}>,
     *     changed: bool
     * }
     */
    public function diff(int $revisionId, int $siteId, ?int $againstRevisionId = null): array
    {
        $revision = $this->get($revisionId, $siteId);

        $against = null;

        if ($againstRevisionId !== null) {
            $against = $this->get($againstRevisionId, $siteId);

            if ((int) $against->contentId !== (int) $revision->contentId) {
                throw new \InvalidArgumentException('A revision can only be compared with one of the same content.');
            }
        } else {
            foreach ($this->listFor((int) $revision->contentId, $siteId) as $candidate) {
                if ((int) $candidate->revisionNumber === (int) $revision->revisionNumber - 1) {
                    $against = $candidate;

                    break;
                }
            }
        }

        $before = $this->blocksOf($against?->contentJson);
        $after = $this->blocksOf($revision->contentJson);

        $blocks = [];
        $count = max(count($before), count($after));

        for ($index = 0; $index < $count; $index++) {
            $old = $before[$index] ?? null;
            $new = $after[$index] ?? null;

            if ($old === $new) {
                continue;
            }

            $status = match (true) {
                $old === null => 'added',
                $new === null => 'removed',
                default => 'changed',
            };

            $blocks[] = [
                'index' => $index,
                'status' => $status,
                'from' => (string) ($old ?? ''),
                'to' => (string) ($new ?? ''),
            ];
        }

        $titleFrom = (string) ($against?->title ?? '');
        $titleTo = (string) $revision->title;
        $excerptFrom = (string) ($against?->excerpt ?? '');
        $excerptTo = (string) ($revision->excerpt ?? '');

        return [
            'title' => ['from' => $titleFrom, 'to' => $titleTo],
            'excerpt' => ['from' => $excerptFrom, 'to' => $excerptTo],
            'blocks' => $blocks,
            'changed' => $titleFrom !== $titleTo || $excerptFrom !== $excerptTo || $blocks !== [],
        ];
    }

    /**
     * The readable text of each block, in document order.
     *
     * A closed shape is not assumed: the editor may add a block type tomorrow, and a diff that
     * ignored unknown types would silently claim "no change".
     *
     * @return list<string>
     */
    private function blocksOf(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $block) {
            $out[] = trim($this->flatten(is_array($block) ? ($block['data'] ?? $block) : $block));
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private function flatten(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (!is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $item) {
            $text = $this->flatten($item);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }
    public function restore(int $revisionId, int $siteId, int $restoredBy): int
    {
        $revision = $this->get($revisionId, $siteId);

        // Snapshot the state being replaced, so a restore is itself undoable.
        $this->snapshot($revision->contentId, $siteId, $restoredBy);

        $blocks = null;

        if ($revision->contentJson !== null && $revision->contentJson !== '') {
            $decoded = json_decode($revision->contentJson, true);
            $blocks = is_array($decoded) ? $decoded : null;
        }

        try {
            $this->contents->update($revision->contentId, $siteId, [
                'title' => $revision->title,
                'excerpt' => $revision->excerpt,
                'content_json' => $blocks,
            ]);
        } catch (ContentNotFoundException) {
            throw new ContentMissingException();
        }

        return $revision->contentId;
    }

    public function countFor(int $contentId, int $siteId): int
    {
        return $this->revisions->countFor($contentId, $siteId);
    }
}
