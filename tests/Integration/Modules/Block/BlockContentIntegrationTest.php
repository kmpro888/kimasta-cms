<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Block;

use CMS\Modules\Block\BlockServiceProvider;
use CMS\Modules\Block\Contracts\BlockRendererInterface;
use CMS\Modules\Block\Service\BlockCompiler;
use CMS\Modules\Block\Service\BlockMigrator;
use CMS\Modules\Block\Service\BlockRegistry;
use CMS\Modules\Block\Service\BlockSanitizer;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\ContentServiceProvider;
use CMS\Modules\Content\Repository\PdoContentRepository;
use CMS\Modules\Content\Repository\PdoUrlRegistryRepository;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\PlainBlockCompiler;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\Support\ModuleDatabaseTestCase;
use Tests\Support\RecordingEventDispatcher;

/**
 * Proof that the Block engine actually drives Content's stored HTML.
 *
 * This is the integration the task pack cares about: "Compiled HTML can be saved
 * to `contents`". Content depends on `BlockCompilerInterface`; the Block module
 * supplies the implementation. Swapping it must change what lands in
 * `content_html` with zero changes to Content.
 */
final class BlockContentIntegrationTest extends ModuleDatabaseTestCase
{
    private function contentService(BlockCompilerInterface $compiler): ContentServiceInterface
    {
        $repository = new PdoContentRepository($this->db);

        return new ContentService(
            $repository,
            new SlugService($repository),
            new UrlGenerator(new PdoUrlRegistryRepository($this->db)),
            $compiler,
            new ContentTypeRegistry(),
            new RecordingEventDispatcher(),
            new FrozenClock(),
        );
    }

    private function blockCompiler(): BlockCompiler
    {
        $registry = new BlockRegistry();

        return new BlockCompiler($registry, new BlockSanitizer(), new BlockMigrator($registry));
    }

    #[Test]
    public function contentStoredHtmlComesFromTheBlockEngine(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $contents = $this->contentService($this->blockCompiler());

        $content = $contents->create($siteId, [
            'title' => 'Block Driven',
            'author_id' => 1,
            'content_json' => [
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Title', 'level' => 2]],
                ['type' => 'paragraph', 'version' => 1, 'data' => ['text' => 'Body']],
                ['type' => 'list', 'version' => 1, 'data' => ['items' => ['A', 'B']]],
            ],
        ]);

        self::assertStringContainsString('<h2>Title</h2>', (string) $content->contentHtml);
        self::assertStringContainsString('<p>Body</p>', (string) $content->contentHtml);
        self::assertStringContainsString('<li>A</li>', (string) $content->contentHtml);
        self::assertStringContainsString('Title', (string) $content->contentPlain);
    }

    #[Test]
    public function theBlockEngineAndTheFallbackCompilerDiffer(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $blocks = [
            ['type' => 'table', 'version' => 1, 'data' => ['rows' => [['H1', 'H2']], 'header' => true]],
        ];

        $viaFallback = $this->contentService(new PlainBlockCompiler())
            ->create($siteId, ['title' => 'Fallback', 'author_id' => 1, 'content_json' => $blocks]);

        $viaEngine = $this->contentService($this->blockCompiler())
            ->create($siteId, ['title' => 'Engine', 'author_id' => 1, 'content_json' => $blocks]);

        // The plain fallback ignores tables; the block engine renders them.
        self::assertStringNotContainsString('<table>', (string) $viaFallback->contentHtml);
        self::assertStringContainsString('<table>', (string) $viaEngine->contentHtml);
        self::assertStringContainsString('<th>H1</th>', (string) $viaEngine->contentHtml);
    }

    #[Test]
    public function restoredHtmlIsSanitizedBeforeStorage(): void
    {
        $siteId = $this->createSiteRow('main', 'example.com');

        $content = $this->contentService($this->blockCompiler())->create($siteId, [
            'title' => 'XSS Through Blocks',
            'author_id' => 1,
            'content_json' => [
                ['type' => 'html', 'version' => 1, 'data' => ['html' => '<p>ok</p><script>alert(1)</script>']],
            ],
        ]);

        self::assertStringContainsString('<p>ok</p>', (string) $content->contentHtml);
        self::assertStringNotContainsString('<script>', (string) $content->contentHtml);
    }

    #[Test]
    public function theProviderRebindsTheContentCompiler(): void
    {
        // Mirrors what app/bootstrap.php does: Content first, Block second.
        $container = new \CMS\Core\Container\Container();
        $container->instance(
            \CMS\Shared\Contracts\ConfigInterface::class,
            new \CMS\Core\Config\Config([
                'app' => ['base_path' => CMS_TEST_ROOT, 'storage_path' => $this->path('storage')],
                'database' => [],
                'logging' => [],
                'security' => [],
                'media' => [],
            ])
        );
        $container->instance(\CMS\Shared\Contracts\ClockInterface::class, new FrozenClock());
        $container->instance(
            \CMS\Shared\Contracts\EventDispatcherInterface::class,
            new RecordingEventDispatcher()
        );
        $container->instance(\CMS\Core\Database\Connection::class, $this->db);

        (new ContentServiceProvider())->register($container);

        $before = $container->get(BlockCompilerInterface::class);
        self::assertInstanceOf(PlainBlockCompiler::class, $before);

        (new BlockServiceProvider())->register($container);

        $after = $container->get(BlockCompilerInterface::class);
        self::assertInstanceOf(BlockCompiler::class, $after);
        self::assertInstanceOf(BlockRendererInterface::class, $after);
    }
}
