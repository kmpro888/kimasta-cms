<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Revision;

use CMS\Modules\Revision\Contracts\AutosaveServiceInterface;
use CMS\Modules\Revision\Contracts\RevisionServiceInterface;
use CMS\Modules\Revision\Repository\PdoRevisionRepository;
use CMS\Modules\Revision\Repository\RevisionRepositoryInterface;
use CMS\Modules\Revision\Service\AutosaveService;
use CMS\Modules\Revision\Service\RevisionService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Revision contract conformance.
 */
final class RevisionContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'revision service' => [RevisionServiceInterface::class, RevisionService::class],
            'autosave service' => [AutosaveServiceInterface::class, AutosaveService::class],
            'repository' => [RevisionRepositoryInterface::class, PdoRevisionRepository::class],
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
    public function theRevisionCapIsPartOfTheContract(): void
    {
        // The cap is a product rule, so it is declared on the interface rather
        // than buried in an implementation constant.
        self::assertSame(3, RevisionServiceInterface::MAX_PER_CONTENT);
    }

    #[Test]
    public function revisionAndAutosaveAreSeparateConcerns(): void
    {
        $revision = new ReflectionClass(RevisionServiceInterface::class);
        $autosave = new ReflectionClass(AutosaveServiceInterface::class);

        // Autosave must not expose snapshot/restore; that is revision territory.
        self::assertTrue($revision->hasMethod('snapshot'));
        self::assertFalse($autosave->hasMethod('snapshot'));

        // Revision must not expose autosave save/clear.
        self::assertFalse($revision->hasMethod('save'));
        self::assertTrue($autosave->hasMethod('save'));
    }

    #[Test]
    public function theRevisionServiceDependsOnContentByContractNotTable(): void
    {
        $constructor = (new ReflectionClass(RevisionService::class))->getConstructor();

        self::assertNotNull($constructor);

        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters()
        );

        // It must consume the Content *service* contract, never a repository or
        // a raw connection pointed at `contents`.
        self::assertContains(\CMS\Modules\Content\Contracts\ContentServiceInterface::class, $types);

        foreach ($types as $type) {
            self::assertStringNotContainsStringIgnoringCase('PdoContentRepository', $type);
        }
    }
}
