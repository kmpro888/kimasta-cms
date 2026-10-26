<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Time source abstraction so tests and DST-sensitive logic stay deterministic.
 *
 * FROZEN CONTRACT.
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;

    public function timestamp(): int;
}
