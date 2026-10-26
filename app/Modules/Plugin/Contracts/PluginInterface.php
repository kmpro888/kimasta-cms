<?php

declare(strict_types=1);

namespace CMS\Modules\Plugin\Contracts;

use CMS\Modules\Plugin\Service\PluginContext;

/**
 * The single entry point a plugin implements.
 *
 * A plugin is a class with one method. Everything it contributes is declared through
 * the context, so a plugin cannot reach into core, and core never has to know a
 * plugin's name.
 *
 * FROZEN CONTRACT.
 */
interface PluginInterface
{
    /**
     * Register contributions. Called once per request for an installed plugin.
     *
     * Must not perform I/O: this runs during boot, before routing, and a slow plugin
     * would slow every request including cached pages.
     */
    public function register(PluginContext $context): void;
}
