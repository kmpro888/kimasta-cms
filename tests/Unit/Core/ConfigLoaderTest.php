<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CMS\Core\Config\ConfigLoader;
use CMS\Core\Config\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\TestCase;

#[CoversClass(ConfigLoader::class)]
#[CoversClass(Env::class)]
final class ConfigLoaderTest extends TestCase
{
    public function testEnvParsesQuotesAndComments(): void
    {
        $file = $this->path('.env');
        file_put_contents($file, implode("\n", [
            '# comment',
            'PLAIN=value',
            'QUOTED="quoted value"',
            'SINGLE=\'single value\'',
            'WITH_COMMENT=abc # trailing',
            'export EXPORTED=yes',
            'FLAG=true',
            'NUMBER=42',
            '',
        ]));

        $env = new Env();
        $env->load($file);

        self::assertSame('value', $env->get('PLAIN'));
        self::assertSame('quoted value', $env->get('QUOTED'));
        self::assertSame('single value', $env->get('SINGLE'));
        self::assertSame('abc', $env->get('WITH_COMMENT'));
        self::assertSame('yes', $env->get('EXPORTED'));
        self::assertTrue($env->bool('FLAG'));
        self::assertSame(42, $env->int('NUMBER'));
    }

    public function testEnvMissingFileIsSilent(): void
    {
        $env = new Env();
        $env->load($this->path('does-not-exist'));

        self::assertTrue($env->loaded());
        self::assertNull($env->get('ANYTHING'));
    }

    public function testLoaderOverlaysConfigFilesWithEnvironment(): void
    {
        $configDir = $this->path('config');
        mkdir($configDir, 0775, true);

        file_put_contents(
            $configDir . '/app.php',
            "<?php return ['debug' => false, 'timezone' => 'UTC', 'name' => 'CMS'];"
        );
        file_put_contents(
            $this->path('.env'),
            "APP_DEBUG=true\nAPP_TIMEZONE=Asia/Bangkok\n"
        );

        $env = new Env();
        $env->load($this->path('.env'));

        $config = (new ConfigLoader($configDir, $env))->load();

        self::assertTrue($config->get('app.debug'));
        self::assertSame('Asia/Bangkok', $config->get('app.timezone'));
        self::assertSame('CMS', $config->get('app.name'));
    }

    public function testLoaderSkipsNonArrayConfigFiles(): void
    {
        $configDir = $this->path('config');
        mkdir($configDir, 0775, true);
        file_put_contents($configDir . '/bad.php', '<?php return "not an array";');

        $env = new Env();
        $env->load($this->path('.env'));

        $config = (new ConfigLoader($configDir, $env))->load();

        self::assertFalse($config->has('bad'));
    }
}