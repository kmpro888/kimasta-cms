<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Theme;

use CMS\Modules\Theme\Contracts\TemplateResolverInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Contracts\ViewRendererInterface;
use CMS\Modules\Theme\Entity\ViewModel;
use CMS\Modules\Theme\Service\TemplateResolver;
use CMS\Modules\Theme\Service\ThemeRegistry;
use CMS\Modules\Theme\Service\ViewRenderer;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Theme contract conformance, with the "theme cannot query" rule asserted
 * structurally.
 */
final class ThemeContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'registry' => [ThemeRegistryInterface::class, ThemeRegistry::class],
            'resolver' => [TemplateResolverInterface::class, TemplateResolver::class],
            'renderer' => [ViewRendererInterface::class, ViewRenderer::class],
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
    public function theRendererCannotHandATemplateADatabase(): void
    {
        // "Theme MUST NOT query database" enforced structurally: the renderer takes
        // only a theme registry, and the ViewModel exposes no accessors to I/O.
        $constructor = (new ReflectionClass(ViewRenderer::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();

            self::assertStringNotContainsStringIgnoringCase('Repository', $type);
            self::assertStringNotContainsStringIgnoringCase('Connection', $type);
            self::assertStringNotContainsStringIgnoringCase('Container', $type);
        }

        foreach ((new ReflectionClass(ViewModel::class))->getMethods() as $method) {
            self::assertNotContains(
                $method->getName(),
                ['db', 'pdo', 'query', 'container', 'repository'],
                'ViewModel must not expose an I/O accessor: ' . $method->getName()
            );
        }
    }

    #[Test]
    public function theResolverExposesItsHierarchy(): void
    {
        // Making the candidate list public is what lets an admin explain why a
        // template was chosen.
        self::assertTrue(
            (new ReflectionClass(TemplateResolverInterface::class))->hasMethod('candidates')
        );
    }

    #[Test]
    public function theRegistryReturnsAValidatedManifestNotAnArray(): void
    {
        $returnType = (string) (new ReflectionClass(ThemeRegistryInterface::class))
            ->getMethod('get')
            ->getReturnType();

        self::assertSame(\CMS\Modules\Theme\Entity\ThemeManifest::class, $returnType);
    }
}
