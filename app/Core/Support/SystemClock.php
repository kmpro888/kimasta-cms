<?php

declare(strict_types=1);

namespace CMS\Core\Support;

use CMS\Shared\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function __construct(private readonly string $timezone = 'UTC')
    {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
    }

    public function timestamp(): int
    {
        return time();
    }
}
