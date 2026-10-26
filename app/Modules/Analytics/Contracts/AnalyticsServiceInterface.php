<?php

declare(strict_types=1);

namespace CMS\Modules\Analytics\Contracts;

/**
 * Third-party analytics snippet rendering.
 *
 * The product does **not** collect page views locally: writing a row per visitor is
 * exactly the kind of load a shared host cannot take, and the task pack forbids it.
 * Instead the configured provider's snippet is rendered once, centrally.
 *
 * Because this is rendered into every page, it is the one place where a config value
 * becomes executable JavaScript. Every identifier is therefore validated against a
 * strict pattern before it is emitted.
 *
 * FROZEN CONTRACT.
 */
interface AnalyticsServiceInterface
{
    /**
     * The snippet for a site, or an empty string when analytics is disabled.
     */
    public function render(int $siteId): string;

    /**
     * The configured provider: `none`, `google`, or `matomo`.
     */
    public function provider(int $siteId): string;
}
