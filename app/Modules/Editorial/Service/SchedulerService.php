<?php

declare(strict_types=1);

namespace CMS\Modules\Editorial\Service;

use CMS\Core\Filesystem\LockInterface;
use CMS\Modules\Editorial\Contracts\ScheduledContentFinderInterface;
use CMS\Modules\Editorial\Contracts\SchedulerServiceInterface;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Publishes content whose scheduled time has arrived.
 *
 * Built for cron on shared hosting, which imposes three constraints:
 *
 *  - **Concurrency**: two overlapping runs must not double-publish. A named
 *    advisory lock serializes them, and `flock` releases automatically if the
 *    process dies, so a crashed run cannot wedge the scheduler.
 *  - **Bounded work**: one run publishes at most `$limit` rows. A large backlog
 *    is drained across runs rather than holding a request open.
 *  - **No human actor**: publishing happens as the system, recorded with user id
 *    0 so the audit trail shows it was automated rather than silent.
 */
final class SchedulerService implements SchedulerServiceInterface
{
    public function __construct(
        private readonly EditorialWorkflow $workflow,
        private readonly ScheduledContentFinderInterface $finder,
        private readonly LockInterface $lock,
        private readonly ClockInterface $clock,
    ) {
    }

    public function publishDue(?int $siteId = null, int $limit = self::DEFAULT_BATCH): int
    {
        return count($this->run($siteId, $limit));
    }

    public function publishDueForSite(int $siteId, int $limit = self::DEFAULT_BATCH): array
    {
        return $this->run($siteId, $limit);
    }

    public function dueIds(?int $siteId = null, int $limit = self::DEFAULT_BATCH): array
    {
        return array_keys($this->finder->due($this->clock->now(), $siteId, $limit));
    }

    /**
     * @return list<int> content ids actually published
     */
    private function run(?int $siteId, int $limit): array
    {
        if (!$this->lock->acquire(self::LOCK_NAME)) {
            // Another run holds the lock; this invocation yields. That is correct
            // behaviour for cron, not an error.
            return [];
        }

        try {
            $due = $this->finder->due($this->clock->now(), $siteId, max(1, $limit));
            $published = [];

            foreach ($due as $contentId => $rowSiteId) {
                try {
                    // The tenant comes from the row, never from a default.
                    $this->workflow->transitionAsSystem(
                        $contentId,
                        $rowSiteId,
                        'published',
                        'Published by scheduler',
                    );

                    $published[] = $contentId;
                } catch (\Throwable) {
                    // One bad row must not abort the batch; the next run retries.
                    continue;
                }
            }

            return $published;
        } finally {
            $this->lock->release(self::LOCK_NAME);
        }
    }
}
