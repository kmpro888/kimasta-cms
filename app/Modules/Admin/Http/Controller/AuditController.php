<?php

declare(strict_types=1);

namespace CMS\Modules\Admin\Http\Controller;

use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Admin\Http\AdminController;
use CMS\Modules\Admin\Support\AdminView;
use CMS\Modules\Audit\Contracts\AuditLoggerInterface;
use CMS\Modules\Identity\Contracts\ActorProviderInterface;
use CMS\Shared\Contracts\CsrfTokenManagerInterface;
use CMS\Shared\Contracts\SessionInterface;

/**
 * The audit trail, read-only.
 *
 * There is no delete button and no edit form, because the log is evidence. The screen exists so an
 * operator can answer the question a shared-hosting site actually asks: who changed this, and when.
 *
 * Gated on `settings.update` — the administrator capability. A dedicated `audit.read` would be
 * finer, and is noted as a follow-up rather than pretended to exist.
 */
final class AuditController extends AdminController
{
    private const PAGE_SIZE = 50;

    public function __construct(
        AdminView $view,
        SessionInterface $session,
        CsrfTokenManagerInterface $csrf,
        ActorProviderInterface $actors,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct($view, $session, $csrf, $actors);
    }

    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');

        $siteId = $this->siteId($request);
        $page = max(1, (int) ($request->queryParam('page') ?? 1));
        $action = trim((string) ($request->queryParam('action') ?? ''));

        $filters = [
            'limit' => self::PAGE_SIZE,
            'offset' => ($page - 1) * self::PAGE_SIZE,
        ];

        if ($action !== '') {
            $filters['action'] = $action;
        }

        $entries = $this->audit->entries($siteId, $filters);
        $total = $this->audit->count($siteId, $action !== '' ? ['action' => $action] : []);

        return $this->html($request, 'audit/index', 'Audit log', [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'action' => $action,
        ]);
    }
}
