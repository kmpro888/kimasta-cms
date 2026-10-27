<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Plugin;

use CMS\Modules\Plugin\Entity\PluginManifest;
use CMS\Modules\Plugin\Exceptions\PluginNotInstalledException;
use CMS\Modules\Plugin\Exceptions\PluginUpToDateException;
use CMS\Modules\Plugin\Repository\PluginRepositoryInterface;
use CMS\Modules\Plugin\Service\PluginCapabilityRegistry;
use CMS\Modules\Plugin\Service\PluginManager;
use CMS\Modules\Plugin\Service\PluginMigrationRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Updating an installed plugin.
 *
 * `install()` was already an upsert, so "update" existed only as "install it again and hope": the
 * screen never said a newer version was on disk, and a click on Update could silently do nothing.
 *
 * These tests run against a **temporary plugin with no migrations**, built with the real manager.
 * Installing the shipped example plugin inside this harness registers its capabilities and disturbs
 * the fixture, which is not what is being tested here — the update path is.
 */
final class PluginUpdateTest extends AdminTestCase
{
    private ?string $pluginRoot = null;

    private function pluginRoot(): string
    {
        if ($this->pluginRoot !== null) {
            return $this->pluginRoot;
        }

        $root = $this->tempDir . '/plugins';
        @mkdir($root . '/tiny', 0775, true);

        file_put_contents($root . '/tiny/plugin.json', json_encode([
            'name' => 'tiny',
            'title' => 'Tiny',
            'version' => '2.0.0',
            'description' => 'A plugin with no migrations.',
            'author' => 'Tests',
            'entry_class' => 'Tiny\\Plugin',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->pluginRoot = $root;
    }

    private function manager(): PluginManager
    {
        $container = $this->app->container();

        return new PluginManager(
            $this->pluginRoot(),
            $container->get(PluginRepositoryInterface::class),
            $container->get(PluginMigrationRunner::class),
            $container->get(PluginCapabilityRegistry::class),
        );
    }

    private function discoverTiny(): PluginManifest
    {
        $manifest = $this->manager()->discover()['tiny'] ?? null;

        self::assertInstanceOf(PluginManifest::class, $manifest, 'The temp plugin must be discoverable.');

        return $manifest;
    }

    private function recordInstalled(string $version): void
    {
        $this->app->container()
            ->get(PluginRepositoryInterface::class)
            ->install('tiny', $version, $this->discoverTiny()->toArray());
    }

    #[Test]
    public function updateBringsAStaleInstallToTheVersionOnDisk(): void
    {
        $this->recordInstalled('1.0.0');

        $manifest = $this->manager()->update('tiny');

        self::assertSame('2.0.0', $manifest->version);

        $installed = $this->app->container()
            ->get(PluginRepositoryInterface::class)
            ->installedVersions()['tiny'] ?? null;

        self::assertSame('2.0.0', $installed);
    }

    #[Test]
    public function updateRefusesAPluginThatIsNotInstalled(): void
    {
        $this->expectException(PluginNotInstalledException::class);

        $this->manager()->update('tiny');
    }

    #[Test]
    public function updateRefusesWhenTheInstallIsAlreadyCurrent(): void
    {
        $this->recordInstalled('2.0.0');

        try {
            $this->manager()->update('tiny');
            self::fail('An update with nothing to do must not report success.');
        } catch (PluginUpToDateException $exception) {
            self::assertSame('tiny', $exception->pluginName);
            self::assertSame('2.0.0', $exception->installedVersion);
            self::assertSame('2.0.0', $exception->availableVersion);
            self::assertSame(409, $exception->getCode());
        }
    }

    #[Test]
    public function updatesListsOnlyInstalledPluginsThatAreBehind(): void
    {
        $this->recordInstalled('2.0.0');
        self::assertSame([], $this->manager()->updates(), 'Nothing to do when the install is current.');

        $this->recordInstalled('1.0.0');

        $updates = $this->manager()->updates();

        self::assertArrayHasKey('tiny', $updates);
        self::assertSame('1.0.0', $updates['tiny']['installed']);
        self::assertSame('2.0.0', $updates['tiny']['available']);
    }

    #[Test]
    public function theScreenOffersTheUpdateOnlyWhenThereIsOne(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $repository = $this->app->container()->get(PluginRepositoryInterface::class);
        $example = $this->app->container()->get(\CMS\Modules\Plugin\Contracts\PluginManagerInterface::class)->discover()['example-plugin'] ?? null;

        self::assertInstanceOf(PluginManifest::class, $example, 'The shipped example plugin must exist.');

        $repository->install('example-plugin', $example->version, $example->toArray());
        self::assertStringNotContainsString('Update available', $this->body($this->get('/admin/plugins')));

        $repository->install('example-plugin', '0.0.1', $example->toArray());

        $html = $this->body($this->get('/admin/plugins'));

        self::assertStringContainsString('Update available', $html);
        self::assertStringContainsString('0.0.1', $html);
        self::assertStringContainsString('action="/admin/plugins/update"', $html);
    }
}
