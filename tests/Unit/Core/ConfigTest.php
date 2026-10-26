<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Config\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testDotNotationReadsNestedValues(): void
    {
        $config = new Config(['app' => ['debug' => true, 'name' => 'CMS']]);

        self::assertTrue($config->get('app.debug'));
        self::assertSame('CMS', $config->get('app.name'));
    }

    public function testMissingKeyReturnsDefault(): void
    {
        $config = new Config(['app' => []]);

        self::assertNull($config->get('app.missing'));
        self::assertSame('fallback', $config->get('app.missing', 'fallback'));
        self::assertSame('fallback', $config->get('unknown.deep.key', 'fallback'));
    }

    public function testHasDetectsPresenceNotTruthiness(): void
    {
        $config = new Config(['a' => ['b' => false, 'c' => null]]);

        self::assertTrue($config->has('a.b'));
        self::assertTrue($config->has('a.c'));
        self::assertFalse($config->has('a.d'));
    }

    public function testSetWritesNestedPath(): void
    {
        $config = new Config();
        $config->set('app.debug', false);

        self::assertFalse($config->get('app.debug'));
    }

    public function testScalarConfigValueIsNotTraversable(): void
    {
        $config = new Config(['app' => 'scalar']);

        self::assertSame('scalar', $config->get('app'));
        self::assertNull($config->get('app.debug'));
    }

    public function testSetManyMergesRecursively(): void
    {
        $config = new Config(['app' => ['name' => 'CMS', 'debug' => false]]);
        $config->setMany(['app' => ['debug' => true]]);

        self::assertSame('CMS', $config->get('app.name'));
        self::assertTrue($config->get('app.debug'));
    }
}