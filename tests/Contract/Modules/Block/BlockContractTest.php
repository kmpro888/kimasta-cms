<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Block;

use CMS\Modules\Block\Contracts\BlockMigrationInterface;
use CMS\Modules\Block\Contracts\BlockRegistryInterface;
use CMS\Modules\Block\Contracts\BlockRendererInterface;
use CMS\Modules\Block\Contracts\BlockSanitizerInterface;
use CMS\Modules\Block\Service\BlockCompiler;
use CMS\Modules\Block\Service\BlockMigrator;
use CMS\Modules\Block\Service\BlockRegistry;
use CMS\Modules\Block\Service\BlockSanitizer;
use CMS\Modules\Content\Contracts\BlockCompilerInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Block contract conformance.
 *
 * The compiler must satisfy BOTH the Block renderer contract and Content's
 * frozen BlockCompilerInterface — that dual role is what lets the block engine
 * plug into Content without Content knowing it exists.
 */
final class BlockContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'registry' => [BlockRegistryInterface::class, BlockRegistry::class],
            'sanitizer' => [BlockSanitizerInterface::class, BlockSanitizer::class],
            'migration' => [BlockMigrationInterface::class, BlockMigrator::class],
            'renderer' => [BlockRendererInterface::class, BlockCompiler::class],
            'content compiler' => [BlockCompilerInterface::class, BlockCompiler::class],
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
    public function theCompilerIsBothARendererAndAContentCompiler(): void
    {
        $reflection = new ReflectionClass(BlockCompiler::class);

        self::assertTrue($reflection->implementsInterface(BlockRendererInterface::class));
        self::assertTrue($reflection->implementsInterface(BlockCompilerInterface::class));
    }

    #[Test]
    public function theRendererNeverDeclaresAPersistenceDependency(): void
    {
        // Structural proof of "renderer MUST NOT query database": the compiler's
        // constructor takes only registry/sanitizer/migrator, none of which can
        // reach a table.
        $constructor = (new ReflectionClass(BlockCompiler::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();

            self::assertStringNotContainsStringIgnoringCase('Repository', $type);
            self::assertStringNotContainsStringIgnoringCase('Connection', $type);
            self::assertStringNotContainsStringIgnoringCase('Pdo', $type);
            self::assertStringNotContainsStringIgnoringCase('EntityManager', $type);
        }
    }

    #[Test]
    public function blockShapeRequiresTypeVersionAndData(): void
    {
        $compiler = new BlockCompiler(
            new BlockRegistry(),
            new BlockSanitizer(),
            new BlockMigrator(new BlockRegistry()),
        );

        // A block missing `version` is treated as v1 and still renders.
        $html = $compiler->toHtml([
            ['type' => 'paragraph', 'data' => ['text' => 'No version given']],
        ]);

        self::assertStringContainsString('No version given', $html);
    }
}
