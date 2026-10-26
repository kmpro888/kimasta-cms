<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Content;

use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentRepositoryInterface;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Contracts\SlugServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Content\Service\ContentQueryService;
use CMS\Modules\Content\Service\ContentService;
use CMS\Modules\Content\Service\ContentTypeRegistry;
use CMS\Modules\Content\Service\SlugService;
use CMS\Modules\Content\Service\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Content contract conformance.
 *
 * These interfaces are new in this wave and every later module (workflow,
 * revisions, SEO, cache, API, blocks) will code against them. This test pins the
 * method surface so a refactor cannot silently rename or narrow one.
 */
final class ContentContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'content service' => [ContentServiceInterface::class, ContentService::class],
            'content repository' => [ContentRepositoryInterface::class, \CMS\Modules\Content\Repository\PdoContentRepository::class],
            'content query' => [ContentQueryServiceInterface::class, ContentQueryService::class],
            'content type registry' => [ContentTypeRegistryInterface::class, ContentTypeRegistry::class],
            'slug service' => [SlugServiceInterface::class, SlugService::class],
            'url generator' => [UrlGeneratorInterface::class, UrlGenerator::class],
        ];
    }

    #[Test]
    public function implementationsMatchTheirContractSurface(): void
    {
        foreach (self::contractPairs() as $label => [$contract, $implementation]) {
            $contractReflection = new ReflectionClass($contract);
            $implementationReflection = new ReflectionClass($implementation);

            self::assertTrue(
                $implementationReflection->implementsInterface($contract),
                $label . ': ' . $implementation . ' does not implement ' . $contract
            );

            foreach ($contractReflection->getMethods() as $method) {
                self::assertTrue(
                    $implementationReflection->hasMethod($method->getName()),
                    $label . ': missing method ' . $method->getName()
                );

                $actual = $implementationReflection->getMethod($method->getName());

                self::assertSame(
                    (string) $method->getReturnType(),
                    (string) $actual->getReturnType(),
                    $label . ': return type drift on ' . $method->getName()
                );

                self::assertSame(
                    count($method->getParameters()),
                    count($actual->getParameters()),
                    $label . ': arity drift on ' . $method->getName()
                );
            }
        }
    }

    #[Test]
    public function contentServiceExposesTheFrozenLifecycle(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ContentServiceInterface::class))->getMethods()
        );

        foreach (['create', 'update', 'get', 'getBySlug', 'trash', 'purge', 'setStatus'] as $required) {
            self::assertContains($required, $methods);
        }
    }

    #[Test]
    public function contentRepositoryIsSiteScopedOnEverySignature(): void
    {
        $methods = (new ReflectionClass(ContentRepositoryInterface::class))->getMethods();

        foreach ($methods as $method) {
            $names = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters()
            );

            self::assertContains(
                'siteId',
                $names,
                $method->getName() . '() must take an explicit site id'
            );
        }
    }

    #[Test]
    public function queryServiceSeparatesReadsFromWrites(): void
    {
        $query = new ReflectionClass(ContentQueryServiceInterface::class);

        foreach (['paginate', 'published', 'findByUrl'] as $read) {
            self::assertTrue($query->hasMethod($read));
        }

        // The read interface must not expose a write path.
        foreach (['create', 'update', 'delete', 'purge'] as $write) {
            self::assertFalse(
                $query->hasMethod($write),
                'ContentQueryServiceInterface must not expose ' . $write . '()'
            );
        }
    }

    #[Test]
    public function coreContentTypesAreRegisteredThroughTheRegistryNotAContractChange(): void
    {
        $registry = new ContentTypeRegistry();

        self::assertTrue($registry->has('post'));
        self::assertTrue($registry->has('page'));
    }
}
