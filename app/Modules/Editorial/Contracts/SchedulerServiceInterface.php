<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Contracts;

/**
 * Publishes content whose scheduled time has arrived.
 *
 * Runs from cron, so it must be safe to invoke concurrently: it takes a named
 * lock and publishes in bounded batches. A batch limit is not a performance tweak
 * — it is what stops one cron run from holding a shared-host request open
 * indefinitely when a large backlog accumulates.
 *
 * FROZEN CONTRACT.
 */
interface SchedulerServiceInterface
{
    /**
     * Name of the advisory lock used to serialize runs.
     */
    public const LOCK_NAME = 'editorial-scheduler';

    /**
     * Default batch size for one run.
     */
    public const DEFAULT_BATCH = 50;

    /**
     * Publish everything due, in batches.
     *
     * @return int number of content rows published
     */
    public function publishDue(?int $siteId = null, int $limit = self::DEFAULT_BATCH): int;

    /**
     * Publish due content for one site and return the affected ids.
     *
     * @return list<int>
     */
    public function publishDueForSite(int $siteId, int $limit = self::DEFAULT_BATCH): array;

    /**
     * Ids that are due right now, without publishing them.
     *
     * @return list<int>
     */
    public function dueIds(?int $siteId = null, int $limit = self::DEFAULT_BATCH): array;
}
