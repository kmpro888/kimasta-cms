<?php

declare(strict_types=1);

namespace CMS\Core\Http\Middleware;

use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Shared\Contracts\ConfigInterface;

/**
 * Baseline security headers on every response, including error responses.
 *
 * Registered as the first global middleware, so a 404 or a 500 carries them too: the pages most
 * likely to be probed are the ones that failed.
 *
 * The policy is conservative and stated rather than aspirational:
 *
 *  - `default-src 'self'` — nothing loads from a third party unless a directive allows it;
 *  - `script-src 'self'` — no inline script at all. The admin confirm dialogs were moved to
 *    `public/assets/admin.js` for exactly this reason;
 *  - `style-src 'self' 'unsafe-inline'` — the theme markup and the two standalone pages (comment
 *    confirmation, password reset) carry inline styles. That is a real relaxation, named here
 *    rather than hidden;
 *  - `frame-ancestors 'none'` plus `X-Frame-Options: DENY` — the site cannot be framed, which is
 *    what stops click-jacking; the second header covers browsers that ignore the first;
 *  - `form-action 'self'` — a form cannot post to another origin.
 *
 * Installing a third-party analytics snippet needs `'unsafe-inline'` in `script-src`. That is a
 * decision an operator makes explicitly through `security.csp_allow_inline_scripts`; the default
 * stays strict.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const CSP_HEADER = 'Content-Security-Policy';

    public function __construct(
        private readonly bool $allowInlineScripts = false,
    ) {
    }

    public static function fromConfig(ConfigInterface $config): self
    {
        return new self((bool) $config->get('security.csp_allow_inline_scripts', false));
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        $headers = [
            self::CSP_HEADER => $this->policy(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        foreach ($headers as $name => $value) {
            // A controller that set its own value keeps it: this supplies the baseline, it does not
            // override a deliberate choice.
            if ($response->header($name) === null) {
                $response->setHeader($name, $value);
            }
        }

        return $response;
    }

    public function policy(): string
    {
        $scriptSource = $this->allowInlineScripts ? "'self' 'unsafe-inline'" : "'self'";

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            'script-src ' . $scriptSource,
            "connect-src 'self'",
        ]);
    }
}
