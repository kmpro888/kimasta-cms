<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Media;

use CMS\Modules\Media\Contracts\ImageProcessorInterface;
use CMS\Modules\Media\Contracts\MediaRepositoryInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Image\ImageProcessor;
use CMS\Modules\Media\Repository\PdoMediaRepository;
use CMS\Modules\Media\Service\MediaService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen Media contract conformance.
 */
final class MediaContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'media service' => [MediaServiceInterface::class, MediaService::class],
            'media repository' => [MediaRepositoryInterface::class, PdoMediaRepository::class],
            'image processor' => [ImageProcessorInterface::class, ImageProcessor::class],
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
    public function repositoryIsSiteScopedOnEverySignature(): void
    {
        foreach ((new ReflectionClass(MediaRepositoryInterface::class))->getMethods() as $method) {
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
    public function imageProcessorExposesCapabilityDetection(): void
    {
        $processor = new ImageProcessor();

        self::assertContains($processor->driver(), ['imagick', 'gd', 'none']);
        self::assertIsBool($processor->isAvailable());
        self::assertIsBool($processor->supportsFormat('webp'));
    }

    #[Test]
    public function mediaServiceExposesTheFrozenPipeline(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(MediaServiceInterface::class))->getMethods()
        );

        foreach (['upload', 'update', 'get', 'listForSite', 'delete'] as $required) {
            self::assertContains($required, $methods);
        }
    }
}
