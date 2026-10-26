<?php

declare(strict_types=1);

namespace CMS\Modules\Analytics\Service;

use CMS\Modules\Analytics\Contracts\AnalyticsServiceInterface;
use CMS\Modules\Site\Contracts\SettingsServiceInterface;

/**
 * Renders the configured analytics snippet.
 *
 * Security is the whole design here. Every value that reaches this class comes from
 * site settings, which an administrator can edit — and whatever it returns is printed
 * into every page as executable script. A single unvalidated value would be stored
 * XSS on a site-wide scale.
 *
 * So each identifier is matched against a strict pattern (a Google measurement id and
 * a Matomo site id / host), and anything that does not match produces **no snippet at
 * all** rather than a mangled one. Failing closed is the only safe default when the
 * output is script.
 *
 * The snippet is built from constants, not from the setting, apart from those
 * validated identifiers. That means there is no string interpolation point a value
 * could escape from.
 */
final class AnalyticsService implements AnalyticsServiceInterface
{
    private const NAMESPACE = 'analytics';

    /** `G-XXXXXXX`, `UA-12345-1`, or a plain `XXXXXXX` property id. */
    private const GOOGLE_ID_PATTERN = '/^[A-Z0-9][A-Z0-9\-]{4,40}$/';

    private const MATOMO_HOST_PATTERN = '/^[a-z0-9]([a-z0-9.\-]{0,190}[a-z0-9])?$/i';

    public function __construct(private readonly SettingsServiceInterface $settings)
    {
    }

    public function provider(int $siteId): string
    {
        $value = $this->settings->get($siteId, 'provider', self::NAMESPACE, 'none');

        return is_string($value) ? $value : 'none';
    }

    public function render(int $siteId): string
    {
        return match ($this->provider($siteId)) {
            'google' => $this->renderGoogle($siteId),
            'matomo' => $this->renderMatomo($siteId),
            // `none`, an unknown provider, or a disabled switch: emit nothing.
            default => '',
        };
    }

    private function renderGoogle(int $siteId): string
    {
        $id = $this->settings->get($siteId, 'google_id', self::NAMESPACE, '');

        if (!is_string($id) || preg_match(self::GOOGLE_ID_PATTERN, $id) !== 1) {
            return '';
        }

        $escaped = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Built as one literal with only the validated id interpolated. The id cannot
        // contain a quote, a slash or whitespace, so it cannot break out of the string
        // literal inside the script.
        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $escaped . '"></script>'
            . "\n" . '<script>window.dataLayer=window.dataLayer||[];'
            . 'function gtag(){dataLayer.push(arguments);}'
            . "gtag('js',new Date());gtag('config','" . $escaped . "');</script>";
    }

    private function renderMatomo(int $siteId): string
    {
        $host = $this->settings->get($siteId, 'matomo_host', self::NAMESPACE, '');
        $site = $this->settings->get($siteId, 'matomo_site_id', self::NAMESPACE, '');

        if (!is_string($host) || preg_match(self::MATOMO_HOST_PATTERN, $host) !== 1) {
            return '';
        }

        $siteIdValue = is_numeric($site) ? (int) $site : 0;

        if ($siteIdValue <= 0) {
            return '';
        }

        // Host is validated to contain only letters, digits, dots and hyphens: no
        // scheme, no path, no quote. It is used with https:// forced, so a configured
        // `http://` cannot downgrade the tracker.
        $hostEscaped = htmlspecialchars($host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<script>'
            . 'var _paq=window._paq=window._paq||[];'
            . "_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);"
            . "(function(){var u='https://" . $hostEscaped . "/';"
            . "_paq.push(['setTrackerUrl',u+'matomo.php']);"
            . "_paq.push(['setSiteId','" . $siteIdValue . "']);"
            . "var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];"
            . "g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();"
            . '</script>';
    }
}
