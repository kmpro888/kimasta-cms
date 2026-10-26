<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Cache;

use CMS\Modules\Cache\Contracts\CacheDependencyInterface;
use CMS\Modules\Cache\Contracts\CacheInvalidationServiceInterface;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Cache\FileCache;
use CMS\Modules\Cache\Page\PageCache;
use CMS\Modules\Cache\Service\CacheInvalidationService;
use CMS\Shared\Contracts\CacheInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Cache contract conformance, including the Agent 01 CacheInterface.
 */
final class CacheContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'cache' => [CacheInterface::class, FileCache::class],
            'page cache' => [PageCacheInterface::class, PageCache::class],
            'invalidation' => [CacheInvalidationServiceInterface::class, CacheInvalidationService::class],
            'dependency' => [CacheDependencyInterface::class, CacheDependency::class],
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
            }
        }
    }

    #[Test]
    public function theAgentOneCacheInterfaceIsImplementedUnchanged(): void
    {
        // FileCache must satisfy the frozen contract exactly; no method added or
        // narrowed.
        $contract = new ReflectionClass(CacheInterface::class);
        $implementation = new ReflectionClass(FileCache::class);

        foreach ($contract->getMethods() as $method) {
            self::assertTrue($implementation->hasMethod($method->getName()));
            self::assertSame(
                count($method->getParameters()),
                count($implementation->getMethod($method->getName())->getParameters()),
                'Arity drift on ' . $method->getName()
            );
        }
    }

    #[Test]
    public function thePageCacheIsSiteScopedOnEveryMethod(): void
    {
        foreach ((new ReflectionClass(PageCacheInterface::class))->getMethods() as $method) {
            $names = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters()
            );

            // isEligible() describes a request, not a tenant, and keyFor() takes a
            // site id explicitly; every other method must carry one.
            if (in_array($method->getName(), ['isEligible'], true)) {
                continue;
            }

            self::assertContains('siteId', $names, $method->getName() . '() must be tenant-scoped');
        }
    }

    #[Test]
    public function thePageCacheHasNoRepositoryDependency(): void
    {
        // The hot path must not be able to reach a database, even accidentally.
        $constructor = (new ReflectionClass(PageCache::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();

            self::assertStringNotContainsStringIgnoringCase('Repository', $type);
            self::assertStringNotContainsStringIgnoringCase('Connection', $type);
        }
    }

    #[Test]
    public function invalidationCoversEveryDocumentedEvent(): void
    {
        $constructor = (new ReflectionClass(CacheInvalidationService::class))->getConstructor();
        self::assertNotNull($constructor);

        // The documented consumable events must all map to a dependency or be
        // explicitly irrelevant; none may fall through silently.
        $service = new CacheInvalidationService(
            new PageCache($this->path('pages')),
            new \Tests\Support\RecordingEventDispatcher(),
        );

        foreach (['content.updated', 'content.published', 'content.deleted', 'term.updated', 'term.deleted', 'theme.activated', 'settings.updated'] as $event) {
            $key = $service->dependencyKeyFor($event, ['id' => 1, 'term_id' => 1, 'theme' => 'x', 'site_id' => 1]);

            self::assertNotNull($key, 'Event "' . $event . '" has no dependency mapping.');
        }
    }
}
