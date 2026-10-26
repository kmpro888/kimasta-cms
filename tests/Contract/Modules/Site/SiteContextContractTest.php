<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Site;

use CMS\Modules\Site\Entity\Site;
use CMS\Shared\Contracts\SiteContextInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen SiteContextInterface conformance.
 *
 * This test exists so a refactor cannot quietly widen, narrow or rename the
 * contract that every other module codes against.
 */
#[CoversClass(Site::class)]
final class SiteContextContractTest extends TestCase
{
    private function site(): Site
    {
        return new Site(
            siteId: 5,
            siteSlug: 'main',
            siteName: 'Main Site',
            hostName: 'example.com',
            locale: 'id',
            tz: 'Asia/Jakarta',
            siteBaseUrl: 'https://example.com',
            active: true,
            theme: 'default',
            createdAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            updatedAt: new DateTimeImmutable('2026-01-02 00:00:00'),
        );
    }

    #[Test]
    public function itImplementsTheFrozenInterface(): void
    {
        self::assertInstanceOf(SiteContextInterface::class, $this->site());
    }

    #[Test]
    public function itExposesExactlyTheFrozenMethods(): void
    {
        $contract = new ReflectionClass(SiteContextInterface::class);
        $implementation = new ReflectionClass(Site::class);

        foreach ($contract->getMethods() as $method) {
            self::assertTrue(
                $implementation->hasMethod($method->getName()),
                'Site is missing contract method: ' . $method->getName()
            );

            $actual = $implementation->getMethod($method->getName());

            self::assertSame(
                (string) $method->getReturnType(),
                (string) $actual->getReturnType(),
                'Return type drift on: ' . $method->getName()
            );

            self::assertSame(
                count($method->getParameters()),
                count($actual->getParameters()),
                'Arity drift on: ' . $method->getName()
            );
        }
    }

    #[Test]
    public function accessorsReturnTheConstructedValues(): void
    {
        $site = $this->site();

        self::assertSame(5, $site->id());
        self::assertSame('main', $site->slug());
        self::assertSame('Main Site', $site->name());
        self::assertSame('example.com', $site->host());
        self::assertSame('id', $site->defaultLocale());
        self::assertSame('Asia/Jakarta', $site->timezone());
        self::assertSame('https://example.com', $site->baseUrl());
        self::assertTrue($site->isActive());
    }

    #[Test]
    public function withHostReturnsANewInstanceAndLeavesTheOriginalUntouched(): void
    {
        $original = $this->site();
        $derived = $original->withHost('alias.example.org');

        self::assertNotSame($original, $derived);
        self::assertSame('example.com', $original->host());
        self::assertSame('alias.example.org', $derived->host());
        self::assertSame($original->id(), $derived->id());
    }
}
