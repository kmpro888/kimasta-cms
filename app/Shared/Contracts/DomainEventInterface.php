<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Immutable domain event.
 *
 * FROZEN CONTRACT.
 */
interface DomainEventInterface
{
    public function name(): string;

    public function occurredAt(): \DateTimeImmutable;

    public function siteId(): ?int;

    public function payload(): array;
}
