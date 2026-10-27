<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Seo;

use CMS\Core\Http\Request;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AdminTestCase;

/**
 * Redirect hits and CSV import.
 *
 * Counting is the interesting decision: a database write per redirect is the load this project
 * refuses for public traffic, so a hit is one appended line and cron folds the lines in later. The
 * tests check both halves of that trade — the visit does **not** touch the row, and the flush does.
 */
final class RedirectHitsAndImportTest extends AdminTestCase
{
    private function redirects(): RedirectServiceInterface
    {
        return $this->app->container()->get(RedirectServiceInterface::class);
    }

    private function hitsInDatabase(string $source): int
    {
        foreach ($this->redirects()->listForSite($this->siteId) as $row) {
            if (($row['source_path'] ?? '') === $source) {
                return (int) ($row['hit_count'] ?? 0);
            }
        }

        return 0;
    }

    #[Test]
    public function aRedirectIsCountedWithoutWritingToTheDatabase(): void
    {
        $this->redirects()->store($this->siteId, '/old-desk', '/new-desk', 301);

        $response = $this->app->handle(Request::create('GET', '/old-desk', $this->host));

        self::assertSame(301, $response->status());
        self::assertSame('/new-desk', $response->header('Location'));

        // The visitor's request cost one appended line, not an UPDATE.
        self::assertSame(0, $this->hitsInDatabase('/old-desk'), 'A page view must not write a row.');
        self::assertSame(1, $this->redirects()->pendingHits($this->siteId));
    }

    #[Test]
    public function theFlushFoldsCountedHitsIntoTheRow(): void
    {
        $this->redirects()->store($this->siteId, '/counted', '/elsewhere', 302);

        $this->app->handle(Request::create('GET', '/counted', $this->host));
        $this->app->handle(Request::create('GET', '/counted', $this->host));

        self::assertSame(2, $this->redirects()->pendingHits($this->siteId));

        $result = $this->redirects()->flushHits($this->siteId);

        self::assertSame(['paths' => 1, 'hits' => 2], $result);
        self::assertSame(2, $this->hitsInDatabase('/counted'));
        self::assertSame(0, $this->redirects()->pendingHits($this->siteId), 'The file is drained.');
    }

    #[Test]
    public function theScreenShowsTheCountsAndWhatIsWaiting(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));
        $this->redirects()->store($this->siteId, '/shown', '/new-home', 301);
        $this->app->handle(Request::create('GET', '/shown', $this->host));

        $html = $this->body($this->get('/admin/redirects'));

        self::assertStringContainsString('<th scope="col">Hits</th>', $html);
        self::assertStringContainsString('hit(s) counted and waiting for the cron flush', $html);
    }

    #[Test]
    public function aCsvImportStoresValidRowsAndReportsTheRest(): void
    {
        $this->loginAs($this->makeUser('boss', 'administrator'));

        $path = $this->tempDir . '/redirects.csv';

        file_put_contents($path, implode("\n", [
            'source,target,status',
            '/from-one,/to-one,301',
            '/from-two,/to-two,302',
            '/broken,not a valid target,301',
            '',
        ]));

        $response = $this->app->handle(Request::create(
            'POST',
            '/admin/redirects/import',
            $this->host,
            [],
            ['_token' => $this->app->container()->get(\CMS\Shared\Contracts\CsrfTokenManagerInterface::class)->token()],
            [],
            [],
            null,
            ['csv' => [
                'name' => 'redirects.csv',
                'type' => 'text/csv',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
            ]]
        ));

        self::assertSame(303, $response->status());

        $stored = array_map(
            static fn (array $row): string => (string) ($row['source_path'] ?? ''),
            $this->redirects()->listForSite($this->siteId)
        );

        self::assertContains('/from-one', $stored);
        self::assertContains('/from-two', $stored);
        self::assertNotContains('/broken', $stored);

        // The report reaches the next page, so an operator sees what was skipped and why.
        $html = $this->body($this->get('/admin/redirects'));

        self::assertStringContainsString('Imported 2 redirect(s).', $html);
        self::assertStringContainsString('Skipped 1', $html);
    }

    #[Test]
    public function importingRequiresTheSettingsCapability(): void
    {
        $this->loginAs($this->makeUser('editor-only', 'editor'));

        $response = $this->app->handle(Request::create('POST', '/admin/redirects/import', $this->host, [], [
            '_token' => $this->app->container()->get(\CMS\Shared\Contracts\CsrfTokenManagerInterface::class)->token(),
        ]));

        self::assertSame(403, $response->status());
    }
}
