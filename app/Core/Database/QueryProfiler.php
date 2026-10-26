<?php

declare(strict_types=1);

namespace CMS\Core\Database;

/**
 * Development-only query instrumentation.
 *
 * Collects query count and duration so tests can assert that cached public
 * requests stay at zero queries. Disabled entirely in production.
 */
final class QueryProfiler
{
    /** @var list<array{sql: string, duration: float}> */
    private array $queries = [];

    private float $totalDuration = 0.0;

    public function record(string $sql, float $duration): void
    {
        $this->queries[] = ['sql' => $sql, 'duration' => $duration];
        $this->totalDuration += $duration;
    }

    public function count(): int
    {
        return count($this->queries);
    }

    public function totalDuration(): float
    {
        return $this->totalDuration;
    }

    /** @return list<array{sql: string, duration: float}> */
    public function queries(): array
    {
        return $this->queries;
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->totalDuration = 0.0;
    }
}