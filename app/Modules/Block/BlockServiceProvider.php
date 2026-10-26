<?php

declare(strict_types=1);

namespace CMS\Modules\Block;

use CMS\Core\Bootstrap\ServiceProviderInterface;
use CMS\Core\Container\Container;
use CMS\Modules\Block\Contracts\BlockMigrationInterface;
use CMS\Modules\Block\Contracts\BlockRegistryInterface;
use CMS\Modules\Block\Contracts\BlockRendererInterface;
use CMS\Modules\Block\Contracts\BlockSanitizerInterface;
use CMS\Modules\Block\Service\BlockCompiler;
use CMS\Modules\Block\Service\BlockMigrator;
use CMS\Modules\Block\Service\BlockRegistry;
use CMS\Modules\Block\Service\BlockSanitizer;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;

/**
 * Wires the Block engine into the container.
 *
 * The important line here is the rebind of Content's `BlockCompilerInterface`.
 * Content stores `content_html` / `content_plain` through that contract, so
 * swapping the implementation here is what upgrades the whole CMS from the
 * plain fallback compiler to the real 20-block engine — with no change to the
 * Content module.
 */
final class BlockServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            BlockRegistryInterface::class,
            static fn (): BlockRegistryInterface => new BlockRegistry()
        );

        $container->singleton(
            BlockSanitizerInterface::class,
            static fn (): BlockSanitizerInterface => new BlockSanitizer()
        );

        $container->singleton(
            BlockMigrationInterface::class,
            static fn (Container $c): BlockMigrationInterface => new BlockMigrator(
                $c->get(BlockRegistryInterface::class)
            )
        );

        $container->singleton(BlockRendererInterface::class, static function (Container $c): BlockRendererInterface {
            return new BlockCompiler(
                $c->get(BlockRegistryInterface::class),
                $c->get(BlockSanitizerInterface::class),
                $c->get(BlockMigrationInterface::class),
            );
        });

        // Overrides the plain fallback bound by Content. Deliberately last-wins:
        // the block engine is the authority on compilation.
        $container->singleton(
            BlockCompilerInterface::class,
            static fn (Container $c): BlockCompilerInterface => $c->get(BlockRendererInterface::class)
        );
    }

    public function boot(Container $container): void
    {
        // No routes: the editor UI belongs to a later agent.
    }
}
