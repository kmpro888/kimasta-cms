<?php

declare(strict_types=1);

namespace Tests\Contract\Modules\Seo;

use CMS\Modules\Seo\Contracts\BreadcrumbServiceInterface;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Contracts\SeoServiceInterface;
use CMS\Modules\Seo\Contracts\SitemapServiceInterface;
use CMS\Modules\Seo\Repository\PdoRedirectRepository;
use CMS\Modules\Seo\Repository\PdoSeoRepository;
use CMS\Modules\Seo\Repository\RedirectRepositoryInterface;
use CMS\Modules\Seo\Repository\SeoRepositoryInterface;
use CMS\Modules\Seo\Service\BreadcrumbService;
use CMS\Modules\Seo\Service\RedirectService;
use CMS\Modules\Seo\Service\SeoService;
use CMS\Modules\Seo\Service\SitemapService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TestCase;

/**
 * Frozen SEO contract conformance.
 */
final class SeoContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function contractPairs(): array
    {
        return [
            'seo' => [SeoServiceInterface::class, SeoService::class],
            'sitemap' => [SitemapServiceInterface::class, SitemapService::class],
            'redirect' => [RedirectServiceInterface::class, RedirectService::class],
            'breadcrumb' => [BreadcrumbServiceInterface::class, BreadcrumbService::class],
            'seo repository' => [SeoRepositoryInterface::class, PdoSeoRepository::class],
            'redirect repository' => [RedirectRepositoryInterface::class, PdoRedirectRepository::class],
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
    public function theSeoServiceIsTheOnlyCanonicalProducer(): void
    {
        // The contract returns a finished SeoMeta, not loose parts: a theme cannot
        // assemble its own canonical from pieces it was given.
        $returnType = (string) (new ReflectionClass(SeoServiceInterface::class))
            ->getMethod('metaFor')
            ->getReturnType();

        self::assertSame(\CMS\Modules\Seo\Entity\SeoMeta::class, $returnType);
    }

    #[Test]
    public function theSitemapIsAnArtifactNotALiveQuery(): void
    {
        $reflection = new ReflectionClass(SitemapServiceInterface::class);

        // It must expose generation, reading and invalidation — i.e. a file
        // lifecycle, not a per-request query method.
        self::assertTrue($reflection->hasMethod('generate'));
        self::assertTrue($reflection->hasMethod('read'));
        self::assertTrue($reflection->hasMethod('invalidate'));
        self::assertTrue($reflection->hasMethod('pathFor'));
    }

    #[Test]
    public function theRedirectServiceTakesASiteIdEverywhere(): void
    {
        foreach ((new ReflectionClass(RedirectServiceInterface::class))->getMethods() as $method) {
            $names = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters()
            );

            self::assertContains('siteId', $names, $method->getName() . '() must be tenant-scoped');
        }
    }

    #[Test]
    public function redirectLookupReturnsBothTargetAndStatus(): void
    {
        $doc = (string) (new ReflectionClass(RedirectServiceInterface::class))->getMethod('resolve')->getDocComment();

        self::assertStringContainsString('target_url', $doc);
        self::assertStringContainsString('status_code', $doc);
    }

    #[Test]
    public function seoFieldsAreExplicitColumnsNotMetaKeys(): void
    {
        // Guards the architecture rule: no `meta_key`/`meta_value` for core SEO.
        $doc = (string) (new ReflectionClass(SeoRepositoryInterface::class))->getDocComment();

        self::assertStringContainsString('no generic meta table', $doc);
    }
}
