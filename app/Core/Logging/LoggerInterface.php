<?php

declare(strict_types=1);

namespace CMS\Core\Logging;

/**
 * Lightweight application logger contract.
 */
interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function withContext(array $context): static;
}