<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Taxonomy;

use CMS\Modules\Taxonomy\Contracts\TaxonomyRegistryInterface;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Taxonomy\Contracts\TermRepositoryInterface;
use CMS\Modules\Taxonomy\Repository\PdoTermRepository;
use CMS\Modules\Taxonomy\Service\TaxonomyRegistry;
use CMS\Modules\Taxonomy\Service\TaxonomyService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Taxonomy contract conformance.
 */
final class TaxonomyContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'registry' => [TaxonomyRegistryInterface::class, TaxonomyRegistry::class],
            'service' => [TaxonomyServiceInterface::class, TaxonomyService::class],
            'term repository' => [TermRepositoryInterface::class, PdoTermRepository::class],
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
    public function termRepositoryIsSiteScopedOnEveryMethod(): void
    {
        foreach ((new ReflectionClass(TermRepositoryInterface::class))->getMethods() as $method) {
            $names = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters()
            );

            $hasSite = in_array('siteId', $names, true) || in_array('taxonomyId', $names, true);

            self::assertTrue(
                $hasSite,
                $method->getName() . '() must be tenant-scoped'
            );
        }
    }

    #[Test]
    public function theServiceExposesTheBatchLoader(): void
    {
        // The archive path depends on this: without it, a listing becomes N+1.
        self::assertTrue(
            (new ReflectionClass(TaxonomyServiceInterface::class))->hasMethod('termsForContents')
        );
    }

    #[Test]
    public function defaultTaxonomiesHaveTheRequiredHierarchyFlags(): void
    {
        $registry = new TaxonomyRegistry();

        self::assertTrue($registry->get('category')['hierarchical']);
        self::assertFalse($registry->get('tag')['hierarchical']);
    }
}
