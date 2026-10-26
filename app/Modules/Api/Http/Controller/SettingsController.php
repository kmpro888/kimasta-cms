<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Http\Controller;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\Response;
use CMS\Modules\Api\Http\ApiController;
use CMS\Modules\Api\Validation\Validator;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;

/**
 * Settings endpoints.
 *
 * A setting write emits `settings.updated` from the service, which the cache layer
 * consumes — so an API settings change invalidates the public pages that declared
 * `settings:{siteId}` exactly like an admin change would.
 */
final class SettingsController extends ApiController
{
    /**
     * A deliberate allow-list: settings are not an arbitrary key-value store exposed
     * to the network. A typo or a probing client cannot create junk rows.
     */
    private const ALLOWED_KEYS = [
        'general' => ['site_title', 'site_tagline', 'site_description', 'language'],
        'reading' => ['posts_per_page', 'show_on_front', 'page_on_front'],
        'seo' => ['sitemap_enabled', 'robots_index', 'og_image_id'],
        'media' => ['thumbnail_size', 'medium_size', 'large_size'],
    ];

    public function __construct(private readonly SettingsServiceInterface $settings)
    {
    }

    /**
     * GET /settings?namespace=general
     */
    public function index(Request $request): Response
    {
        $this->requireCapability($request, 'settings.read');
        $siteId = $this->siteId($request);

        $namespace = $this->namespaceParam($request);
        $values = $this->settings->all($siteId, $namespace);

        // Only allow-listed keys leave the service, so a setting added later is not
        // exposed until someone decides it should be.
        $filtered = [];

        foreach (self::ALLOWED_KEYS[$namespace] ?? [] as $key) {
            $filtered[$key] = $values[$key] ?? null;
        }

        return $this->ok(['namespace' => $namespace, 'values' => $filtered]);
    }

    /**
     * PUT /settings
     */
    public function update(Request $request): Response
    {
        $this->requireCapability($request, 'settings.update');
        $siteId = $this->siteId($request);

        $data = $request->jsonBody();
        $namespace = is_string($data['namespace'] ?? null) ? $data['namespace'] : 'general';
        $values = $data['values'] ?? null;

        $validator = Validator::make($data)->required('values');

        $this->ensureValid($validator);

        if (!is_array($values)) {
            throw new HttpException(422, 'The submitted data is invalid.', 'VALIDATION_ERROR', [
                'fields' => ['values' => ['This field must be an object of settings.']],
            ]);
        }

        if (!isset(self::ALLOWED_KEYS[$namespace])) {
            throw new HttpException(422, 'Unknown settings namespace.', 'VALIDATION_ERROR', [
                'fields' => ['namespace' => ['This namespace is not writable.']],
            ]);
        }

        $allowed = self::ALLOWED_KEYS[$namespace];
        $rejected = array_diff(array_keys($values), $allowed);

        if ($rejected !== []) {
            throw new HttpException(422, 'Unknown settings key.', 'VALIDATION_ERROR', [
                'fields' => ['values' => ['Not writable: ' . implode(', ', $rejected) . '.']],
            ]);
        }

        foreach ($values as $key => $value) {
            $this->settings->set($siteId, (string) $key, $value, $namespace);
        }

        return $this->ok([
            'namespace' => $namespace,
            'values' => $this->settings->all($siteId, $namespace),
        ]);
    }

    private function namespaceParam(Request $request): string
    {
        $namespace = $request->queryParam('namespace');
        $namespace = is_string($namespace) && $namespace !== '' ? $namespace : 'general';

        if (!isset(self::ALLOWED_KEYS[$namespace])) {
            throw new HttpException(422, 'Unknown settings namespace.', 'VALIDATION_ERROR', [
                'fields' => ['namespace' => ['This namespace is not readable.']],
            ]);
        }

        return $namespace;
    }
}
