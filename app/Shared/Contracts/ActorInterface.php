<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Authenticated (or anonymous) acting principal.
 *
 * FROZEN CONTRACT — authentication belongs to Agent 03.
 */
interface ActorInterface
{
    public function id(): ?int;

    public function siteId(): int;

    public function authenticated(): bool;

    public function can(
        string $capability,
        mixed $resource = null
    ): bool;
}
