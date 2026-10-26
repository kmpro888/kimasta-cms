<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Service;

use CMS\Core\Routing\Router;
use CMS\Modules\Block\Contracts\BlockRegistryInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Plugin\Contracts\HookRegistryInterface;
use CMS\Modules\Plugin\Contracts\PluginManagerInterface;
use CMS\Modules\Plugin\Entity\PluginManifest;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface;

/**
 * What a plugin is given.
 *
 * Deliberately narrow: registries, hooks and the router — nothing else. A plugin
 * cannot obtain the container, a database connection from core, or another module's
 * repository, which is what "plugins must not edit core" means in practice.
 *
 * Every contribution is recorded against the plugin's name, so deactivating the plugin
 * can remove exactly its contributions.
 */
final class PluginContext
{
    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly HookRegistryInterface $hooks,
        private readonly ContentTypeRegistryInterface $contentTypes,
        private readonly TaxonomyRegistryInterface $taxonomies,
        private readonly BlockRegistryInterface $blocks,
        private readonly Router $router,
        private readonly PluginCapabilityRegistry $capabilities,
        private readonly PluginManagerInterface $manager,
        private readonly SiteResolverInterface $sites,
    ) {
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function name(): string
    {
        return $this->manifest->name;
    }

    public function hooks(): HookRegistryInterface
    {
        return $this->hooks;
    }

    public function contentTypes(): ContentTypeRegistryInterface
    {
        return $this->contentTypes;
    }

    public function taxonomies(): TaxonomyRegistryInterface
    {
        return $this->taxonomies;
    }

    public function blocks(): BlockRegistryInterface
    {
        return $this->blocks;
    }

    /**
     * Read-only access to the router for `route()`.
     */
    public function router(): Router
    {
        return $this->router;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $definition
     */
    public function contentType(string $key, array $definition = []): void
    {
        $this->contentTypes->register($key, $definition);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function taxonomy(string $name, array $definition = []): void
    {
        $this->taxonomies->register($name, $definition);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function block(string $type, array $definition = []): void
    {
        $this->blocks->register($type, $definition);
    }

    public function action(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks->addAction($hook, $callback, $priority);
    }

    public function filter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks->addFilter($hook, $callback, $priority);
    }

    /**
     * Declare a capability this plugin introduces.
     *
     * @param list<string> $roles role slugs that receive it by default
     */
    public function capability(string $key, string $name, string $description = '', array $roles = []): void
    {
        $this->capabilities->declare($this->manifest->name, $key, $name, $description, $roles);
    }

    /**
     * Register a plugin route.
     *
     * The handler is wrapped with an activation gate, so a route contributed by a
     * plugin that is inactive for the resolved tenant answers 404 rather than running.
     * Without that, activating a plugin on Site A would expose its endpoints on every
     * other tenant.
     */
    public function route(string $method, string $path, callable $handler): void
    {
        $this->router->add(
            $method,
            $path,
            new PluginRouteHandler($handler, $this->manifest->name, $this->manager, $this->sites)
        );
    }
}
