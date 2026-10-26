<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Contracts;

/**
 * Finds scheduled content whose publish time has passed.
 *
 * Editorial owns `workflow_logs`; Content owns `contents` and therefore owns the
 * `scheduled_at` column. Rather than query a foreign table, Editorial declares the
 * question and Content answers it — the same seam used for
 * `MediaUsageCheckerInterface`.
 *
 * The result maps **content id => site id** rather than returning bare ids,
 * because a scheduler run may span tenants and the tenant must never be guessed.
 *
 * FROZEN CONTRACT.
 */
interface ScheduledContentFinderInterface
{
    /**
     * Content due for publication right now.
     *
     * @param int|null $siteId Restrict to one tenant; null means all tenants.
     *
     * @return array<int, int> content id => site id
     */
    public function due(?\DateTimeImmutable $now = null, ?int $siteId = null, int $limit = 50): array;
}
