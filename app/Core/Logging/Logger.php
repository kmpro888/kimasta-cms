<?php

declare(strict_types=1);

namespace CMS\Core\Logging;

use CMS\Core\Config\Config;
use CMS\Shared\Contracts\ClockInterface;

/**
 * Minimal line-based file logger.
 *
 * Fields written: timestamp, level, request_id, site_id, message, context.
 * Secret-looking context keys are redacted before serialisation so that
 * accidental logging of credentials cannot leak into storage.
 */
final class Logger implements LoggerInterface
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(
        private readonly string $path,
        private readonly string $minLevel = 'warning',
        private readonly ?ClockInterface $clock = null,
        array $context = [],
    ) {
        $this->context = $context;
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): static
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);

        return $clone;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }

        if (!$this->shouldLog($level)) {
            return;
        }

        $merged = array_merge($this->context, $context);
        $merged = $this->redact($merged);

        $now = $this->clock?->now() ?? new \DateTimeImmutable('now');

        $record = [
            'timestamp' => $now->format(\DateTimeInterface::ATOM),
            'level' => $level,
            'request_id' => $merged['request_id'] ?? null,
            'site_id' => $merged['site_id'] ?? null,
            'message' => $message,
            'context' => $this->withoutReservedKeys($merged),
        ];

        $line = (string) json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;

        $this->write($line);
    }

    public function shouldLog(string $level): bool
    {
        $minimum = self::LEVELS[strtolower($this->minLevel)] ?? 3;

        return (self::LEVELS[$level] ?? 1) >= $minimum;
    }

    private function write(string $line): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        // LOCK_EX keeps concurrent PHP-FPM workers from interleaving lines.
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $context */
    private function redact(array $context): array
    {
        $sensitive = [
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'authorization', 'cookie', 'set-cookie', 'private_key', 'app_key',
        ];

        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $lower = strtolower($key);

            foreach ($sensitive as $needle) {
                if (str_contains($lower, $needle)) {
                    $context[$key] = '[redacted]';
                    break;
                }
            }

            if (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function withoutReservedKeys(array $context): array
    {
        unset($context['request_id'], $context['site_id']);

        return $context;
    }
}