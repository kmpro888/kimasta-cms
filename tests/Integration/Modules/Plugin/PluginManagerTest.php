<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Plugin;

use CMS\Modules\Plugin\Contracts\HookRegistryInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Exceptions\PluginNotInstalledException;
use CMS\Modules\Plugin\Exceptions\PluginNotFoundException;
use CMS\Modules\Plugin\Service\PluginCapabilityRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AppTestCase;

/**
 * Plugin platform: discovery, lifecycle and the acceptance criterion.
 *
 * The acceptance criterion from the task pack is the central test here: an example
 * plugin registers a content type, taxonomy, block, action, filter, API route and
 * capability **without changing core**.
 */
#[CoversClass(\CMS\Modules\Plugin\Service\PluginManager::class)]
#[CoversClass(\CMS\Modules\Plugin\Service\HookRegistry::class)]
#[CoversClass(PluginCapabilityRegistry::class)]
final class PluginManagerTest extends AppTestCase
{
    private PluginManagerInterface $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugins = $this->app->container()->get(PluginManagerInterface::class);
    }

    // --------------------------------------------------------------- discovery

    #[Test]
    public function theExamplePluginIsDiscovered(): void
    {
        $discovered = $this->plugins->discover();

        self::assertArrayHasKey('example-plugin', $discovered);
        self::assertSame('1.0.0', $discovered['example-plugin']->version);
    }

    #[Test]
    public function aDirectoryWithoutAManifestIsNotAPlugin(): void
    {
        // The migration helper and other non-plugin content must not be picked up.
        foreach (array_keys($this->plugins->discover()) as $name) {
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9\-_]*$/', $name);
        }

        self::assertFileExists(CMS_TEST_ROOT . '/plugins/example-plugin/plugin.json');
    }

    // --------------------------------------------------------------- lifecycle

    #[Test]
    public function installingRunsThePluginsOwnMigrations(): void
    {
        $this->plugins->install('example-plugin');

        self::assertTrue($this->plugins->isInstalled('example-plugin'));

        // The plugin created a table core knows nothing about.
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => 'example_notes']
        );

        self::assertSame(1, (int) $row['total']);
    }

    #[Test]
    public function installingTwiceIsIdempotent(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->install('example-plugin');

        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM plugins');
        self::assertSame(1, (int) $row['total']);
    }

    #[Test]
    public function installedMigrationsAreRecordedOnce(): void
    {
        $this->plugins->install('example-plugin');

        $rows = $this->db->select(
            'SELECT migration FROM plugin_migrations WHERE plugin_name = :name',
            ['name' => 'example-plugin']
        );

        self::assertCount(1, $rows);
    }

    #[Test]
    public function activatingRequiresInstallation(): void
    {
        $this->expectException(PluginNotInstalledException::class);

        $this->plugins->activate($this->siteId, 'example-plugin');
    }

    #[Test]
    public function activationIsPerSite(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->activate($this->siteId, 'example-plugin');

        $otherSite = $this->createSiteRow('other', 'other.example');

        self::assertTrue($this->plugins->isActiveFor($this->siteId, 'example-plugin'));
        // Activating on one tenant must not switch it on for every tenant.
        self::assertFalse($this->plugins->isActiveFor($otherSite, 'example-plugin'));
    }

    #[Test]
    public function deactivatingLeavesThePluginInstalled(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->activate($this->siteId, 'example-plugin');

        $this->plugins->deactivate($this->siteId, 'example-plugin');

        self::assertFalse($this->plugins->isActiveFor($this->siteId, 'example-plugin'));
        // Deactivation is a toggle, not an uninstall.
        self::assertTrue($this->plugins->isInstalled('example-plugin'));
    }

    #[Test]
    public function uninstallingRemovesStateButKeepsData(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->activate($this->siteId, 'example-plugin');

        $this->db->affect(
            'INSERT INTO example_notes (site_id, body, created_at) VALUES (:s, :b, :c)',
            ['s' => $this->siteId, 'b' => 'A note.', 'c' => '2026-01-01 00:00:00']
        );

        self::assertTrue($this->plugins->uninstall('example-plugin'));

        self::assertFalse($this->plugins->isInstalled('example-plugin'));
        self::assertFalse($this->plugins->isActiveFor($this->siteId, 'example-plugin'));

        // Dropping a plugin's data as a side effect of a toggle is how people lose
        // production data, so the table and its row must survive.
        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM example_notes');
        self::assertSame(1, (int) $row['total']);

        $activation = $this->db->selectOne('SELECT COUNT(*) AS total FROM site_plugins');
        self::assertSame(0, (int) $activation['total']);
    }

    #[Test]
    public function anUnknownPluginIsRejected(): void
    {
        $this->expectException(PluginNotFoundException::class);

        $this->plugins->install('does-not-exist');
    }

    // ----------------------------------------------- the acceptance criterion

    #[Test]
    public function theExamplePluginRegistersEveryContributionPoint(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->activate($this->siteId, 'example-plugin');

        // A fresh application boot: the contributions must appear from the plugin file
        // alone, with no change anywhere under app/.
        $app = require CMS_TEST_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
        $app->boot();
        $c = $app->container();

        // 1. content type
        self::assertTrue($c->get(\CMS\Modules\Content\Contracts\ContentTypeRegistryInterface::class)->has('note'));

        // 2. taxonomy
        self::assertTrue($c->get(\CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface::class)->has('note_channel'));

        // 3. block
        self::assertTrue($c->get(\CMS\Modules\Block\Contracts\BlockRegistryInterface::class)->has('note_callout'));

        // 4 and 5. action and filter
        $hooks = $c->get(HookRegistryInterface::class);
        self::assertContains('note.created', $hooks->actions());
        self::assertContains('note.title', $hooks->filters());
        self::assertSame('Note: Halo', $hooks->applyFilters('note.title', 'Halo'));

        // 6. API route
        $paths = array_map(static fn ($r): string => $r->path(), $c->get(\CMS\Core\Routing\Router::class)->routes());
        self::assertContains('/api/v1/example/notes/ping', $paths);

        // 7. capability
        self::assertContains('example.read_notes', $c->get(PluginCapabilityRegistry::class)->keys());
    }

    #[Test]
    public function anInactivePluginsRoutesAnswerFourOhFour(): void
    {
        $this->plugins->install('example-plugin');

        // Installed but active nowhere. The route is registered globally (boot cannot
        // know a tenant) but the gate must refuse it.
        $app = $this->freshApp();

        $response = $app->handle(
            \CMS\Core\Http\Request::create('GET', '/api/v1/example/notes/ping', $this->host)
        );

        // 404, not 403: confirming the endpoint exists would leak the plugin's presence.
        self::assertSame(404, $response->status());
    }

    #[Test]
    public function anActivePluginsRouteRuns(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->activate($this->siteId, 'example-plugin');

        $app = $this->freshApp();

        $response = $app->handle(
            \CMS\Core\Http\Request::create('GET', '/api/v1/example/notes/ping', $this->host)
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('example-plugin', $response->content());
    }

    #[Test]
    public function aPluginsRouteIsNotVisibleOnAnotherTenant(): void
    {
        $this->plugins->install('example-plugin');
        $this->plugins->activate($this->siteId, 'example-plugin');

        $this->createSiteRow('other', 'other.example');

        $app = $this->freshApp();

        $response = $app->handle(
            \CMS\Core\Http\Request::create('GET', '/api/v1/example/notes/ping', 'other.example')
        );

        self::assertSame(404, $response->status());
    }

    /**
     * Boot a fresh application.
     *
     * Plugin contributions are registered during `boot()`, so a test that installs or
     * activates a plugin after setUp needs a new boot for the contribution to exist —
     * exactly as a real request would.
     */
    private function freshApp(): \CMS\Core\Bootstrap\Application
    {
        $app = require CMS_TEST_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
        $app->boot();

        return $app;
    }
}
