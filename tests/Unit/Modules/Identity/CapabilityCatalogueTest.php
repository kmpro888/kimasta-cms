<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use CMS\Modules\Identity\Entity\Role;
use CMS\Modules\Identity\Support\CapabilityCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TestCase;

/**
 * The capability catalogue is data, but it is contractual data: the role matrix
 * decides what every seeded role may do, so drift here silently changes
 * authorisation for the whole product.
 */
#[CoversClass(CapabilityCatalogue::class)]
final class CapabilityCatalogueTest extends TestCase
{
    #[Test]
    public function everySystemRoleHasAMatrixEntry(): void
    {
        $matrix = CapabilityCatalogue::roleMatrix();

        foreach (Role::systemSlugs() as $slug) {
            self::assertArrayHasKey($slug, $matrix, 'Missing role matrix for: ' . $slug);
            self::assertNotEmpty($matrix[$slug], 'Empty role matrix for: ' . $slug);
        }
    }

    #[Test]
    public function everyMatrixCapabilityIsDeclared(): void
    {
        $declared = CapabilityCatalogue::capabilities();

        foreach (CapabilityCatalogue::roleMatrix() as $slug => $keys) {
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $declared, $slug . ' grants an undeclared capability: ' . $key);
            }
        }
    }

    #[Test]
    public function capabilityKeysFollowTheResourceActionConvention(): void
    {
        foreach (array_keys(CapabilityCatalogue::capabilities()) as $key) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/',
                $key,
                'Capability key does not follow <resource>.<action>: ' . $key
            );
        }
    }

    #[Test]
    public function everyCapabilityHasADescription(): void
    {
        foreach (CapabilityCatalogue::capabilities() as $key => $meta) {
            self::assertArrayHasKey('name', $meta);
            self::assertArrayHasKey('description', $meta);
            self::assertNotSame('', $meta['description'], 'Missing description for: ' . $key);
        }
    }

    #[Test]
    public function contributorCannotPublish(): void
    {
        // An explicit acceptance criterion from the task pack.
        self::assertNotContains('content.publish', CapabilityCatalogue::capabilitiesFor(Role::CONTRIBUTOR));
    }

    #[Test]
    public function contributorCanCreateButNotDelete(): void
    {
        $capabilities = CapabilityCatalogue::capabilitiesFor(Role::CONTRIBUTOR);

        self::assertContains('content.create', $capabilities);
        self::assertNotContains('content.delete', $capabilities);
    }

    #[Test]
    public function subscriberIsReadOnly(): void
    {
        self::assertSame(['content.read'], CapabilityCatalogue::capabilitiesFor(Role::SUBSCRIBER));
    }

    #[Test]
    public function reviewerCanReviewButNotPublish(): void
    {
        $capabilities = CapabilityCatalogue::capabilitiesFor(Role::REVIEWER);

        self::assertContains('workflow.review', $capabilities);
        self::assertNotContains('content.publish', $capabilities);
    }

    #[Test]
    public function administratorHoldsEveryDeclaredCapability(): void
    {
        $all = array_keys(CapabilityCatalogue::capabilities());
        $admin = CapabilityCatalogue::capabilitiesFor(Role::ADMINISTRATOR);

        // Compare as sets: declaration order in the matrix is grouped by
        // resource, not alphabetical.
        sort($all);
        sort($admin);

        self::assertSame($all, $admin);
    }

    #[Test]
    public function unknownRoleHasNoCapabilities(): void
    {
        self::assertSame([], CapabilityCatalogue::capabilitiesFor('made-up-role'));
    }
}
