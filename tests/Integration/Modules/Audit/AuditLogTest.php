<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Audit;

use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Audit\Repository\AuditLogRepositoryInterface;
use CMS\Modules\Site\Contracts\SiteServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * The audit trail.
 *
 * Three properties matter, and they are three different tests:
 *
 *  - **it records** privileged actions, with who did them and what they touched;
 *  - **it is tenant-scoped** — a site's log must never show another site's activity;
 *  - **it is append-only** — no update and no delete path exists, because a log that can be
 *    rewritten is not evidence.
 *
 * The screen is read-only and requires `settings.update`; there is no delete button to test because
 * there is no delete action to call.
 */
#[CoversClass(\CMS\Modules\Audit\Service\AuditLogger::class)]
final class AuditLogTest extends AdminTestCase
{
    private function audit(): AuditLoggerInterface
    {
        return $this->app->container()->get(AuditLoggerInterface::class);
    }

    #[Test]
    public function creatingAUserIsRecordedWithWhoAndWhat(): void
    {
        $this->loginAs($this->makeUser('auditor', 'administrator'));

        $response = $this->post('/admin/users', [
            'username' => 'recorded',
            'email' => 'recorded@example.test',
            'password' => 'a-real-password',
            'display_name' => 'Recorded User',
            'role' => 'editor',
        ]);

        self::assertSame(303, $response->status());

        $entries = $this->audit()->entries($this->siteId, ['action' => 'user.']);
        self::assertCount(1, $entries);

        $entry = $entries[0];

        self::assertSame('user.created', $entry->action);
        self::assertSame('user', $entry->subjectType);
        self::assertSame('recorded', $entry->context['username'] ?? null);
        self::assertSame($this->siteId, $entry->siteId);
        self::assertNotSame('', $entry->actorName, 'The actor is snapshotted, not resolved later.');
        self::assertSame('Auditor', $entry->actorName);
    }

    #[Test]
    public function deletingAUserIsRecordedAfterTheUserIsGone(): void
    {
        $this->loginAs($this->makeUser('auditor', 'administrator'));
        $victim = $this->makeUser('temporary', 'editor');

        self::assertSame(303, $this->post('/admin/users/' . $victim . '/delete')->status());

        $entries = $this->audit()->entries($this->siteId, ['action' => 'user.deleted']);

        self::assertCount(1, $entries);
        self::assertSame($victim, $entries[0]->subjectId);
        self::assertSame('temporary', $entries[0]->context['username'] ?? null);
    }

    #[Test]
    public function creatingASiteIsRecordedAgainstTheNewSite(): void
    {
        $this->loginAs($this->makeUser('auditor', 'administrator'));

        $this->post('/admin/sites', [
            'name' => 'Audited Site',
            'slug' => 'audited',
            'domain' => 'audited.example',
            'default_locale' => 'en',
            'timezone' => 'UTC',
            'admin_username' => 'auditedadmin',
            'admin_email' => 'auditedadmin@example.test',
            'admin_password' => 'a-real-password',
        ]);

        $site = $this->app->container()->get(SiteServiceInterface::class)->findBySlug('audited');
        $entries = $this->audit()->entries((int) $site->id(), ['action' => 'site.created']);

        self::assertCount(1, $entries, 'The entry belongs to the site that was created.');
        self::assertSame((int) $site->id(), $entries[0]->subjectId);
        self::assertSame('audited.example', $entries[0]->context['domain'] ?? null);

        // And it is not in the creating site's log: the action happened to another tenant.
        self::assertSame([], $this->audit()->entries($this->siteId, ['action' => 'site.created']));
    }

    #[Test]
    public function oneSitesTrailIsInvisibleToAnother(): void
    {
        $this->log('settings.updated', 'settings', null, ['note' => 'first tenant only']);

        $otherSite = $this->createSiteRow('other-tenant', 'other.example');

        self::assertSame([], $this->audit()->entries($otherSite));
        self::assertNotSame([], $this->audit()->entries($this->siteId));
    }

    #[Test]
    public function theActionFilterMatchesAnExactNameAndAGroup(): void
    {
        $this->log('user.created', 'user', 1);
        $this->log('user.updated', 'user', 1);
        $this->log('settings.updated', 'site', 1);

        self::assertCount(2, $this->audit()->entries($this->siteId, ['action' => 'user.']));
        self::assertCount(1, $this->audit()->entries($this->siteId, ['action' => 'settings.updated']));
        self::assertCount(3, $this->audit()->entries($this->siteId));
    }

    #[Test]
    public function theScreenIsReadOnlyAndRequiresTheCapability(): void
    {
        $this->loginAs($this->makeUser('editor-user', 'editor'));

        self::assertSame(403, $this->get('/admin/audit')->status());
    }

    #[Test]
    public function theScreenShowsTheRecordedActions(): void
    {
        $this->log('plugin.activated', 'plugin', null, ['plugin' => 'example']);

        $this->loginAs($this->makeUser('auditor', 'administrator'));

        $html = $this->body($this->get('/admin/audit'));

        self::assertStringContainsString('plugin.activated', $html);
        self::assertStringContainsString('example', $html);
        self::assertStringContainsString('Append-only', $html);

        // No delete form on a log: there is nothing to delete with.
        self::assertStringNotContainsString('/admin/audit/delete', $html);
    }

    #[Test]
    public function theRepositoryOffersNoWayToRewriteHistory(): void
    {
        $reflection = new \ReflectionClass(AuditLogRepositoryInterface::class);

        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods()
        );

        self::assertNotContains('update', $methods);
        self::assertNotContains('delete', $methods);
        self::assertNotContains('remove', $methods);
        self::assertNotContains('clear', $methods);
        self::assertContains('append', $methods);
    }

    /**
     * @param array<string, scalar|list<string>|null> $context
     */
    private function log(string $action, ?string $subjectType, ?int $subjectId, array $context = []): void
    {
        $this->audit()->record(
            $this->siteId,
            null,
            $action,
            $subjectType,
            $subjectId,
            $context
        );
    }
}
