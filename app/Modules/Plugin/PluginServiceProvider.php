<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Core\Database\Connection;
use CMS\Core\Routing\Router;
use CMS\Modules\Block\Contracts\BlockRegistryInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Identity\Contracts\ExtraCapabilityProviderInterface;
use CMS\Modules\Plugin\Contracts\HookRegistryInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Repository\PdoPluginRepository;
use CMS\Modules\Plugin\Repository\PluginRepositoryInterface;
use CMS\Modules\Plugin\Service\HookRegistry;
use CMS\Modules\Plugin\Service\PluginCapabilityRegistry;
use CMS\Modules\Plugin\Service\PluginContext;
use CMS\Modules\Plugin\Service\PluginManager;
use CMS\Modules\Plugin\Service\PluginMigrationRunner;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface;
use CMS\Shared\Contracts\ConfigInterface;

/**
 * Wires the plugin platform.
 *
 * This provider is both a service provider and the capability contributor Identity
 * consults. Identity declares `ExtraCapabilityProviderInterface` and never learns what
 * a plugin is; the dependency points inward.
 *
 * `boot()` is where installed plugins register their contributions. It runs after every
 * provider's `register()`, so the registries a plugin writes into already exist.
 */
final class PluginServiceProvider implements ServiceProviderInterface, ExtraCapabilityProviderInterface
{
    private ?PluginCapabilityRegistry $capabilityRegistry = null;

    public function register(Container $container): void
    {
        $container->singleton(
            PluginRepositoryInterface::class,
            static fn (Container $c): PluginRepositoryInterface => new PdoPluginRepository($c->get(Connection::class))
        );

        $container->singleton(
            PluginCapabilityRegistry::class,
            function (Container $c): PluginCapabilityRegistry {
                return $this->capabilityRegistry = new PluginCapabilityRegistry();
            }
        );

        $container->singleton(
            HookRegistryInterface::class,
            static fn (): HookRegistryInterface => new HookRegistry()
        );

        $container->singleton(
            PluginMigrationRunner::class,
            static fn (Container $c): PluginMigrationRunner => new PluginMigrationRunner(
                $c->get(Connection::class),
                $c->get(PluginRepositoryInterface::class),
            )
        );

        $container->singleton(PluginManagerInterface::class, static function (Container $c): PluginManagerInterface {
            /** @var ConfigInterface $config */
            $config = $c->get(ConfigInterface::class);

            return new PluginManager(
                self::pluginRoot($config),
                $c->get(PluginRepositoryInterface::class),
                $c->get(PluginMigrationRunner::class),
                $c->get(PluginCapabilityRegistry::class),
            );
        });

        // Identity's optional seam.
        $container->singleton(
            ExtraCapabilityProviderInterface::class,
            fn (): ExtraCapabilityProviderInterface => $this
        );
    }

    public function boot(Container $container): void
    {
        /** @var PluginManagerInterface $manager */
        $manager = $container->get(PluginManagerInterface::class);

        /** @var HookRegistryInterface $hooks */
        $hooks = $container->get(HookRegistryInterface::class);

        $registries = [
            'contentTypes' => $container->get(ContentTypeRegistryInterface::class),
            'taxonomies' => $container->get(TaxonomyRegistryInterface::class),
            'blocks' => $container->get(BlockRegistryInterface::class),
            'router' => $container->get(Router::class),
            'capabilities' => $container->get(PluginCapabilityRegistry::class),
            'sites' => $container->get(SiteResolverInterface::class),
        ];

        $manager->bootInstalled(
            static fn (string $name): PluginContext => new PluginContext(
                $manager->discover()[$name],
                $hooks,
                $registries['contentTypes'],
                $registries['taxonomies'],
                $registries['blocks'],
                $registries['router'],
                $registries['capabilities'],
                $manager,
                $registries['sites'],
            ),
            $hooks,
        );
    }

    public function extraCapabilities(): array
    {
        // The registry is resolved lazily, after plugins have registered. Asking the
        // container here keeps this method free of a constructor dependency loop.
        return $this->registry()?->capabilities() ?? [];
    }

    public function extraRoleGrants(): array
    {
        return $this->registry()?->roleGrants() ?? [];
    }

    private function registry(): ?PluginCapabilityRegistry
    {
        return $this->capabilityRegistry;
    }

    /**
     * Absolute path to the plugins root.
     */
    public static function pluginRoot(ConfigInterface $config): string
    {
        $configured = (string) $config->get('plugin.path', '');

        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        $base = (string) $config->get('app.base_path', '');

        if ($base === '') {
            $base = dirname(__DIR__, 3);
        }

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'plugins';
    }
}
