<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Logging\Logger;
use CMS\Core\Support\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TestCase;

#[CoversClass(Logger::class)]
final class LoggerTest extends TestCase
{
    private function logPath(): string
    {
        return $this->path('logs/app.log');
    }

    private function logger(string $minLevel = 'debug'): Logger
    {
        return new Logger($this->logPath(), $minLevel, new SystemClock());
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        if (!is_file($this->logPath())) {
            return [];
        }

        $lines = file($this->logPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(
            static fn (string $line): array => (array) json_decode($line, true),
            array_values($lines)
        );
    }

    public function testWritesJsonLines(): void
    {
        $this->logger()->info('hello', ['request_id' => 'req_abc']);
        $records = $this->records();

        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame('hello', $records[0]['message']);
        self::assertSame('req_abc', $records[0]['request_id']);
        self::assertArrayHasKey('timestamp', $records[0]);
    }

    public function testMinimumLevelSuppressesLowerLevels(): void
    {
        $logger = $this->logger('warning');
        $logger->debug('skipped');
        $logger->info('skipped');
        $logger->warning('kept');
        $logger->error('kept too');

        $messages = array_column($this->records(), 'message');

        self::assertSame(['kept', 'kept too'], $messages);
    }

    public function testSecretsAreRedacted(): void
    {
        $this->logger()->error('login failed', [
            'password' => 'hunter2',
            'api_key' => 'sk-live-123',
            'nested' => ['authorization' => 'Bearer abc'],
            'safe' => 'visible',
        ]);

        $line = (string) file_get_contents($this->logPath());

        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringNotContainsString('sk-live-123', $line);
        self::assertStringNotContainsString('Bearer abc', $line);
        self::assertStringContainsString('visible', $line);
        self::assertStringContainsString('[redacted]', $line);
    }

    public function testWithContextMergesWithoutMutatingOriginal(): void
    {
        $base = $this->logger();
        $scoped = $base->withContext(['site_id' => 7]);

        $scoped->info('scoped');

        self::assertSame(7, $this->records()[0]['site_id']);
        self::assertSame([], $base->withContext([])->jsonSerializeContext ?? []);
    }

    public function testCreatesLogDirectory(): void
    {
        $this->logger()->error('x');

        self::assertDirectoryExists($this->path('logs'));
    }
}
