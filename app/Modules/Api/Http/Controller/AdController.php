<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Advertising\Contracts\AdvertisingServiceInterface;
use CMS\Modules\Advertising\Entity\Ad;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Validation\Validator;

/**
 * Ad management.
 *
 * Requires `ads.manage`, which only the administrator role holds by default. That is
 * deliberate: an ad body is raw HTML printed on every page, so writing one is equivalent
 * to writing a template file. Granting it to editors would quietly turn "may edit
 * articles" into "may inject site-wide markup".
 */
final class AdController extends ApiController
{
    public function __construct(private readonly AdvertisingServiceInterface $ads)
    {
    }

    /**
     * GET /ads
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');
        $siteId = $this->siteId($request);

        $placement = $request->queryParam('placement');
        $placement = is_string($placement) && $placement !== '' ? $placement : null;

        return $this->ok(array_map(
            fn (Ad $ad): array => $this->project($ad),
            $this->ads->listForSite($siteId, $placement)
        ));
    }

    /**
     * POST /ads
     */
    public function store(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->required('name', 'placement', 'body_html')
            ->string('name', 190)
            ->string('body_html', 20000)
            ->string('target_url', 1000)
            ->integer('priority')
            ->boolean('is_active');

        $this->ensureValid($validator);

        $this->assertPlacement($data['placement']);

        $payload = [
            'name' => (string) $data['name'],
            'placement' => (string) $data['placement'],
            'body_html' => (string) $data['body_html'],
        ];

        foreach (['target_url', 'priority', 'is_active', 'starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('rules', $data)) {
            $payload['rules'] = $data['rules'];
        }

        try {
            $ad = $this->ads->create($siteId, $payload);
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalid($exception->getMessage());
        }

        return $this->ok($this->project($ad), [], 201);
    }

    /**
     * PATCH /ads/{id}
     */
    public function update(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        $existing = null;

        foreach ($this->ads->listForSite($siteId) as $candidate) {
            if ($candidate->id === $id) {
                $existing = $candidate;
                break;
            }
        }

        if ($existing === null) {
            throw HttpException::notFound();
        }

        $data = $request->jsonBody();
        $validator = Validator::make($data)
            ->string('name', 190)
            ->string('body_html', 20000)
            ->string('target_url', 1000)
            ->integer('priority')
            ->boolean('is_active');

        $this->ensureValid($validator);

        if (array_key_exists('placement', $data)) {
            $this->assertPlacement($data['placement']);
        }

        $payload = [];

        foreach (['name', 'placement', 'body_html', 'target_url', 'priority', 'is_active', 'starts_at', 'ends_at', 'rules'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($payload === []) {
            throw $this->invalid('No updatable field was supplied.');
        }

        try {
            $ad = $this->ads->update($id, $siteId, $payload);
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalid($exception->getMessage());
        }

        return $this->ok($this->project($ad));
    }

    /**
     * DELETE /ads/{id}
     */
    public function destroy(Request $request): Response
    {
        $this->requireCapability($request, 'ads.manage');
        $siteId = $this->siteId($request);
        $id = $this->intParam($request, 'id');

        if (!$this->ads->delete($id, $siteId)) {
            throw HttpException::notFound();
        }

        return new Response('', 204);
    }

    private function assertPlacement(mixed $placement): void
    {
        if (!is_string($placement) || !Ad::isValidPlacement($placement)) {
            throw $this->invalid('Unknown placement. Allowed: ' . implode(', ', Ad::PLACEMENTS) . '.');
        }
    }

    private function invalid(string $message): HttpException
    {
        return new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
            'fields' => ['ad' => [$message]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Ad $ad): array
    {
        return [
            'id' => $ad->id,
            'name' => $ad->name,
            'placement' => $ad->placement,
            'body_html' => $ad->bodyHtml,
            'target_url' => $ad->targetUrl,
            'is_active' => $ad->isActive,
            'priority' => $ad->priority,
            'starts_at' => $ad->startsAt?->format(DATE_ATOM),
            'ends_at' => $ad->endsAt?->format(DATE_ATOM),
            'rules' => $ad->rules,
            'created_at' => $ad->createdAt->format(DATE_ATOM),
            'updated_at' => $ad->updatedAt->format(DATE_ATOM),
        ];
    }
}
