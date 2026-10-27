<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Exceptions\InvalidRedirectException;
use CMS\Modules\Seo\Exceptions\RedirectLoopException;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * Redirects from an old path to a new one.
 *
 * The service, the validation and the middleware that applies them already existed; only the screen
 * did not, so a redirect could only be added through the API or SQL.
 *
 * Authorization is `settings.update`: a redirect is site configuration, and it can send a public
 * URL anywhere, so it is not something an author should be able to change while writing.
 *
 * Validation stays in the service -- supported status codes, no root redirect, no protocol-relative
 * or off-site target that is not http(s), no self-redirect. This controller only turns a refusal
 * into a message on the form.
 */
final class RedirectController extends AdminController
{
    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly RedirectServiceInterface $redirects,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        return $this->page($request);
    }

    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $source = $this->stringParam($request, 'source_path');
        $target = $this->stringParam($request, 'target_url');
        $status = $this->intParam($request, 'status_code', 301);

        try {
            $this->redirects->store($this->siteId($request), $source, $target, $status);
        } catch (InvalidRedirectException|RedirectLoopException $exception) {
            // The service's message is written for a person, so it is shown as-is.
            return $this->page($request, $exception->getMessage(), [
                'source_path' => $source,
                'target_url' => $target,
                'status_code' => $status,
            ], 422);
        }

        $this->flash('success', 'Redirect added.');

        return $this->redirect('/admin/redirects');
    }

    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $source = $this->stringParam($request, 'source_path');

        if (!$this->redirects->delete($this->siteId($request), $source)) {
            $this->flash('error', 'That redirect was not found.');

            return $this->redirect('/admin/redirects');
        }

        $this->flash('success', 'Redirect deleted.');

        return $this->redirect('/admin/redirects');
    }

    /**
     * Import a CSV of redirects.
     *
     * The column order is `source, target, status`. A header row is skipped by **content**, not by
     * position, so a file without one still works. Rows are validated one at a time and the report
     * says how many were stored and how many were refused, with the first few reasons: an importer
     * that silently drops half a file is worse than no importer.
     */
    public function import(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $upload = $request->file('csv');

        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Choose a CSV file to import.');

            return $this->redirect('/admin/redirects');
        }

        $path = (string) ($upload['tmp_name'] ?? '');

        if ($path === '' || !is_file($path)) {
            $this->flash('error', 'The uploaded file could not be read.');

            return $this->redirect('/admin/redirects');
        }

        if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            $this->flash('error', 'That file is larger than 2 MB. Split it.');

            return $this->redirect('/admin/redirects');
        }

        $siteId = $this->siteId($request);
        $stored = 0;
        $skipped = [];
        $line = 0;

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->flash('error', 'The uploaded file could not be read.');

            return $this->redirect('/admin/redirects');
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;

            // fgetcsv returns [null] for a blank line.
            if ($row === [null] || count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $source = trim((string) ($row[0] ?? ''));
            $target = trim((string) ($row[1] ?? ''));
            $status = (int) trim((string) ($row[2] ?? '301'));

            if ($line === 1 && (str_starts_with(strtolower($source), 'source') || str_starts_with(strtolower($source), 'from'))) {
                continue;
            }

            try {
                $this->redirects->store($siteId, $source, $target, $status === 0 ? 301 : $status);
                $stored++;
            } catch (InvalidRedirectException|RedirectLoopException $exception) {
                if (count($skipped) < 5) {
                    $skipped[] = 'line ' . $line . ': ' . $exception->getMessage();
                }
            }
        }

        fclose($handle);

        $message = 'Imported ' . $stored . ' redirect(s).';

        if ($skipped !== []) {
            $message .= ' Skipped ' . count($skipped) . ': ' . implode(' ', $skipped);
        }

        $this->flash($stored > 0 ? 'success' : 'error', $message);

        return $this->redirect('/admin/redirects');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function page(Request $request, ?string $error = null, array $values = [], int $status = 200): Response
    {
        return $this->html($request, 'redirects/index', 'Redirects', [
            'items' => $this->redirects->listForSite($this->siteId($request)),
            'error' => $error,
            'values' => $values + ['source_path' => '', 'target_url' => '', 'status_code' => 301],
            'statuses' => [301, 302, 307, 308],
            // Counted in a file until cron folds them in. Shown so an operator can tell "no hits
            // yet" from "nobody has run cron".
            'pending' => $this->redirects->pendingHits($this->siteId($request)),
        ], $status);
    }
}
