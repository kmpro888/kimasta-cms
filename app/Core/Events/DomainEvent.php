<?php

declare(strict_types=1);

namespace CMS\Core\Events;

use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\DomainEventInterface;

/**
 * Immutable domain event value object.
 *
 * Event names follow the frozen convention: `<module>.<entity>.<action>`
 * e.g. `content.article.published`.
 */
final class DomainEvent implements DomainEventInterface
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly string $name,
        private readonly array $payload = [],
        private readonly ?int $siteId = null,
        private readonly ?\DateTimeImmutable $occurredAt = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function create(
        string $name,
        array $payload = [],
        ?int $siteId = null,
        ?ClockInterface $clock = null,
    ): self {
        return new self($name, $payload, $siteId, $clock?->now());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function siteId(): ?int
    {
        return $this->siteId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}
