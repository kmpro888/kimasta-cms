<?php

declare(strict_types=1);

namespace CMS\Modules\Migration\Contracts;

/**
 * WordPress → platform migration.
 *
 * The process is explicit, because a migration that cannot be inspected before it runs is
 * a migration nobody dares run:
 *
 * ```text
 * analyze → plan → batch import → mapping → validation → report
 * ```
 *
 * Three invariants:
 *
 *  - **Idempotent.** A source row that was already imported is skipped, so re-running a
 *    batch after a timeout cannot duplicate content. This is the whole reason
 *    `migration_maps` exists.
 *  - **Batchable.** Every import method takes a limit. A shared host will time out on a
 *    10,000-post migration in one request, so the runner must be able to stop and resume.
 *  - **Nothing is discarded silently.** Anything not carried across is written to
 *    `migration_logs` with the source id, the field and the reason.
 *
 * FROZEN CONTRACT.
 */
interface MigrationServiceInterface
{
    /**
     * What exists in the source, per entity type.
     *
     * @return array<string, array{source_total: int, imported: int, pending: int}>
     */
    public function analyze(int $siteId): array;

    /**
     * What a run would do, without doing it: pending source ids per type.
     *
     * @return array<string, array{source_total: int, imported: int, pending: int}>
     */
    public function plan(int $siteId): array;

    /**
     * Import one batch of one entity type.
     *
     * Returns `next_after` so the caller can resume with a keyset cursor rather than an
     * offset, which stays correct even if source rows are added mid-run.
     *
     * @return array{
     *     type: string,
     *     imported: int,
     *     skipped: int,
     *     failed: int,
     *     next_after: int,
     *     done: bool
     * }
     */
    public function runBatch(int $siteId, string $type, int $limit = 50, int $after = 0): array;

    /**
     * Drain every type in batches. Intended for the CLI, which has no request timeout.
     *
     * @return array<string, array{imported: int, skipped: int, failed: int}>
     */
    public function runAll(int $siteId, int $batchSize = 50, ?callable $progress = null): array;

    /**
     * URL integrity and outcome summary.
     *
     * @return array<string, mixed>
     */
    public function report(int $siteId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function logs(int $siteId, string $level = 'warning', int $limit = 100): array;

    /**
     * Entity types in the order they must be imported: users and terms before content,
     * media before content (for featured images), content before comments.
     *
     * @return list<string>
     */
    public function types(): array;
}
