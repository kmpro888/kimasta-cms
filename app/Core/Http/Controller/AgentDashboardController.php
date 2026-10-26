<?php

declare(strict_types=1);

namespace CMS\Core\Http\Controller;

use CMS\Core\Agent\AgentDefinition;
use CMS\Core\Agent\AgentRegistry;
use CMS\Core\Http\JsonResponse;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;

/**
 * Read-only dashboard for the planned implementation agents.
 *
 * Two representations share one data source: a server-rendered HTML page
 * for humans, and a JSON projection for tooling. Neither performs a
 * database query nor a write, so the page is safe to expose to any
 * authenticated operator and cheap enough to refresh freely.
 */
final class AgentDashboardController implements RequestHandlerInterface
{
    public function __construct(private readonly AgentRegistry $registry)
    {
    }

    public function handle(Request $request): Response
    {
        // The JSON projection is selected by an explicit query flag rather
        // than by Accept negotiation: operators paste `/admin/agents?format=json`
        // into a browser, and a content-negotiated URL would be misleading.
        if ((string) $request->queryParam('format', '') === 'json') {
            return $this->json();
        }

        return $this->html($request);
    }

    private function json(): Response
    {
        $agents = $this->registry->all();

        return new JsonResponse([
            'data' => [
                'summary' => $this->registry->summary(),
                'agents' => array_map(
                    static fn (AgentDefinition $agent): array => $agent->toArray(),
                    $agents,
                ),
            ],
        ]);
    }

    private function html(Request $request): Response
    {
        $agents = $this->registry->all();
        $summary = $this->registry->summary();

        $selectedId = (string) $request->queryParam('agent', '');
        $selected = $selectedId !== '' ? $this->registry->find($selectedId) : null;
        $excerpt = $selected !== null ? $this->registry->excerpt($selected) : '';

        $html = AgentDashboardView::render($agents, $summary, $selected, $excerpt);

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            // The dashboard renders repository state; keep it out of any
            // shared proxy cache even if the deployment adds one later.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
