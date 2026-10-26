<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Theme;

use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Entity\TemplateContext;
use CMS\Modules\Theme\Entity\ThemeManifest;
use CMS\Modules\Theme\Entity\ViewModel;
use CMS\Modules\Theme\Exceptions\TemplateNotFoundException;
use CMS\Modules\Theme\Exceptions\ThemeNotFoundException;
use CMS\Modules\Theme\Service\TemplateResolver;
use CMS\Modules\Theme\Service\ViewRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * Template hierarchy and rendering.
 *
 * Two properties matter: resolution falls back predictably, and a template can
 * only see the ViewModel — never a container or a repository.
 */
#[CoversClass(TemplateResolver::class)]
#[CoversClass(ViewRenderer::class)]
#[CoversClass(ViewModel::class)]
#[CoversClass(TemplateContext::class)]
final class ThemeRenderingTest extends TestCase
{
    private string $themeDir;

    private ThemeRegistryInterface $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeDir = $this->path('themes/stub');
        mkdir($this->themeDir . '/templates', 0775, true);
        mkdir($this->themeDir . '/partials', 0775, true);
        mkdir($this->themeDir . '/assets', 0775, true);

        $manifest = new ThemeManifest(
            name: 'stub',
            title: 'Stub',
            version: '1.0.0',
            description: null,
            author: null,
            directory: $this->themeDir,
        );

        $this->registry = new class ($manifest) implements ThemeRegistryInterface {
            public function __construct(private readonly ThemeManifest $manifest)
            {
            }

            public function all(): array
            {
                return [$this->manifest->name => $this->manifest];
            }

            public function has(string $name): bool
            {
                return $name === $this->manifest->name;
            }

            public function get(string $name): ThemeManifest
            {
                if ($name !== $this->manifest->name) {
                    throw new ThemeNotFoundException($name);
                }

                return $this->manifest;
            }

            public function activeFor(int $siteId): ThemeManifest
            {
                return $this->manifest;
            }

            public function activate(int $siteId, string $name): ThemeManifest
            {
                return $this->get($name);
            }

            public function pathFor(string $name): string
            {
                return $this->get($name)->basePath();
            }
        };
    }

    private function put(string $relative, string $contents): void
    {
        file_put_contents($this->themeDir . DIRECTORY_SEPARATOR . $relative, $contents);
    }

    #[Test]
    public function singleResolvesToTheTypeSpecificTemplate(): void
    {
        $this->put('templates/index.php', 'index');
        $this->put('templates/single.php', 'single');
        $this->put('templates/single-post.php', 'single-post');

        $path = (new TemplateResolver($this->registry))->resolve('stub', TemplateContext::single('post'));

        self::assertStringEndsWith('single-post.php', $path);
    }

    #[Test]
    public function singleFallsBackToGenericSingle(): void
    {
        $this->put('templates/index.php', 'index');
        $this->put('templates/single.php', 'single');

        $path = (new TemplateResolver($this->registry))->resolve('stub', TemplateContext::single('book'));

        self::assertStringEndsWith('single.php', $path);
    }

    #[Test]
    public function everythingFallsBackToIndex(): void
    {
        $this->put('templates/index.php', 'index');

        $resolver = new TemplateResolver($this->registry);

        self::assertStringEndsWith('index.php', $resolver->resolve('stub', TemplateContext::single('post')));
        self::assertStringEndsWith('index.php', $resolver->resolve('stub', TemplateContext::archive('post')));
        self::assertStringEndsWith('index.php', $resolver->resolve('stub', TemplateContext::home()));
        self::assertStringEndsWith('index.php', $resolver->resolve('stub', TemplateContext::notFound()));
    }

    #[Test]
    public function archiveUsesItsOwnHierarchy(): void
    {
        $this->put('templates/index.php', 'index');
        $this->put('templates/archive.php', 'archive');
        $this->put('templates/archive-post.php', 'archive-post');

        $resolver = new TemplateResolver($this->registry);

        self::assertStringEndsWith('archive-post.php', $resolver->resolve('stub', TemplateContext::archive('post')));
        self::assertStringEndsWith('archive.php', $resolver->resolve('stub', TemplateContext::archive('product')));
    }

    #[Test]
    public function candidatesAreReportedInPriorityOrder(): void
    {
        $candidates = (new TemplateResolver($this->registry))->candidates(TemplateContext::single('post'));

        self::assertSame(['single-post.php', 'single.php', 'index.php'], $candidates);
    }

    #[Test]
    public function aMissingTemplateRaisesTemplateNotFound(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        (new TemplateResolver($this->registry))->resolve('stub', TemplateContext::single('post'));
    }

    #[Test]
    public function anUnknownThemeRaisesThemeNotFound(): void
    {
        $this->expectException(ThemeNotFoundException::class);

        (new TemplateResolver($this->registry))->resolve('nope', TemplateContext::single('post'));
    }

    #[Test]
    public function renderReturnsBufferedOutput(): void
    {
        $this->put('templates/index.php', '<?php echo "Hello " . $view->e($view->get("name")); ?>');

        $renderer = new ViewRenderer($this->registry);
        $html = $renderer->render(
            $this->themeDir . '/templates/index.php',
            new ViewModel(['name' => 'World'])
        );

        self::assertSame('Hello World', $html);
    }

    #[Test]
    public function theViewModelEscapesByDefault(): void
    {
        $view = new ViewModel(['value' => '<script>alert(1)</script>']);

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $view->e($view->get('value')));
    }

    #[Test]
    public function theViewModelExposesNoContainer(): void
    {
        // Structural guarantee: the object handed to a template has no way to reach
        // a connection or a repository.
        $view = new ViewModel(['a' => 1]);

        self::assertFalse(method_exists($view, 'container'));
        self::assertFalse(method_exists($view, 'db'));
        self::assertFalse(method_exists($view, 'getRepository'));
    }

    #[Test]
    public function withMergesValuesWithoutMutatingTheOriginal(): void
    {
        $view = new ViewModel(['a' => 1]);
        $child = $view->with(['b' => 2]);

        self::assertFalse($view->has('b'));
        self::assertTrue($child->has('b'));
        self::assertSame(1, $child->get('a'));
    }

    #[Test]
    public function partialsRenderFromTheThemesPartialDirectory(): void
    {
        $this->put('partials/notice.php', '<?php echo $view->e($view->get("notice")); ?>');

        $renderer = new ViewRenderer($this->registry);

        self::assertSame('Careful', $renderer->partial('stub', 'notice', new ViewModel(['notice' => 'Careful'])));
    }

    #[Test]
    public function aMissingPartialRaisesTemplateNotFound(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        (new ViewRenderer($this->registry))->partial('stub', 'missing', new ViewModel());
    }

    #[Test]
    public function partialNamesCannotTraverseDirectories(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        (new ViewRenderer($this->registry))->partial('stub', '../../etc/passwd', new ViewModel());
    }

    #[Test]
    public function anUnknownContextKindIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TemplateContext('wizard');
    }
}
