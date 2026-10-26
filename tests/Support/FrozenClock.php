<?php

declare(strict_types=1);

namespace Tests\Support;

use CMS\Shared\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Deterministic clock for tests.
 *
 * Time-sensitive behaviour (reset expiry, last-login stamps) is only testable
 * if the test controls "now", so services take a ClockInterface instead of
 * calling time() directly.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function advanceTo(DateTimeImmutable $at): void
    {
        $this->now = $at;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->advanceSeconds($minutes * 60);
    }
}
