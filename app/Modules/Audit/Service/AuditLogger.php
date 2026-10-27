<?php

declare(strict_types=1);

namespace CMS\Modules\Audit\Service;

use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Audit\Entity\AuditEntry;
use CMS\Modules\Audit\Repository\AuditLogRepositoryInterface;
use CMS\Modules\Identity\Contracts\UserServiceInterface;
use CMS\Shared\Contracts\ActorInterface;

/**
 * Writes the trail, and resolves the actor's name at the moment of the action.
 *
 * The name is looked up once here instead of at read time on purpose: the record has to survive the
 * user being renamed or deleted. A log that says "user #7 did this" about a deleted account is much
 * less useful than one that says who.
 *
 * Everything is caught. An audit write failing must not fail the action it describes — the operator
 * changed a setting, and that change is real whether or not the log table accepted a row.
 */
final class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly UserServiceInterface $users,
    ) {
    }

    public function record(
        int $siteId,
        ?ActorInterface $actor,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = [],
        ?string $ip = null,
    ): void {
        $actorId = $actor?->id();
        $actorName = $this->nameFor($siteId, $actorId);

        try {
            $this->repository->append(
                $siteId,
                $actorId,
                $actorName,
                $action,
                $subjectType,
                $subjectId,
                $context,
                $ip,
            );
        } catch (\Throwable) {
            // Best effort, and deliberately silent: the action already happened, and there is no
            // logger bound in this container to complain to. A failing audit write must never turn
            // a completed change into an error page.
        }
    }

    public function entries(int $siteId, array $filters = []): array
    {
        try {
            return $this->repository->listForSite($siteId, $filters);
        } catch (\Throwable) {
            // The screen degrades to an empty list rather than a 500: an audit table that has not
            // been migrated yet should not take the admin down.
            return [];
        }
    }

    public function count(int $siteId, array $filters = []): int
    {
        try {
            return $this->repository->countForSite($siteId, $filters);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function nameFor(int $siteId, ?int $actorId): string
    {
        if ($actorId === null) {
            return 'system';
        }

        try {
            $user = $this->users->find($actorId, $siteId);

            if ($user === null) {
                return 'user #' . $actorId;
            }

            return $user->displayName !== '' ? $user->displayName : $user->username;
        } catch (\Throwable) {
            return 'user #' . $actorId;
        }
    }
}
