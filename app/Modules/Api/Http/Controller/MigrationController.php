<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Migration\Contracts\MigrationServiceInterface;

/**
 * Migration control.
 *
 * The API exposes inspection and a **single batch**, never a full run: a shared host will
 * time out on a large migration, and a request that half-completes is exactly the situation
 * the idempotent mapping design exists for. `bin/migrate-wp.php` drains everything.
 *
 * Requires `settings.update`, because an import writes to every content table.
 */
final class MigrationController extends ApiController
{
    public function __construct(private readonly MigrationServiceInterface $migration)
    {
    }

    /**
     * GET /migration/analyze
     */
    public function analyze(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        return $this->ok([
            'types' => $this->migration->types(),
            'counts' => $this->migration->analyze($siteId),
        ]);
    }

    /**
     * GET /migration/plan
     */
    public function plan(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        return $this->ok($this->migration->plan($siteId));
    }

    /**
     * POST /migration/batch
     *
     * Body: { type, limit?, after? }
     */
    public function batch(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $type = $data['type'] ?? null;

        if (!is_string($type) || !in_array($type, $this->migration->types(), true)) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['type' => ['Allowed: ' . implode(', ', $this->migration->types()) . '.']],
            ]);
        }

        $limit = (int) ($data['limit'] ?? 50);
        $after = (int) ($data['after'] ?? 0);

        return $this->ok($this->migration->runBatch($siteId, $type, $limit, $after));
    }

    /**
     * GET /migration/report
     */
    public function report(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        return $this->ok($this->migration->report($siteId));
    }

    /**
     * GET /migration/logs?level=warning
     */
    public function logs(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        $level = $request->queryParam('level');
        $level = is_string($level) && $level !== '' ? $level : 'warning';

        return $this->ok($this->migration->logs($siteId, $level, 200));
    }
}
