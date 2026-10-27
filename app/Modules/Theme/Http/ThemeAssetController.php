<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;

/**
 * Serves a theme's static assets.
 *
 * A theme lives outside the web root, which is what stops a theme from being able to run PHP or
 * read `.env`. The consequence is that `/themes/default/assets/theme.css` is not a file the web
 * server can find, so somebody has to hand it out deliberately. That somebody did not exist:
 * `head.php` linked the stylesheet and the link returned 404, so **the public site has never
 * been styled**. Every HTML test still passed, because they check markup and status codes, not
 * appearance.
 *
 * Deliberate choices:
 *
 *  - only installed themes are served, so the route cannot read an arbitrary directory;
 *  - the file name is matched by a strict pattern in the route itself, so it cannot contain a
 *    separator or `..`, and the extensions are an allow-list rather than "anything";
 *  - the content type comes from the extension allow-list, never from the file's contents;
 *  - `nosniff` is sent so a browser cannot decide a CSS file is something else.
 */
final class ThemeAssetController implements RequestHandlerInterface
{
    /**
     * Only these are ever handed out. An allow-list, so adding a new asset type is a decision
     * rather than an accident of what happens to be in the directory.
     */
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    public function __construct(private readonly ThemeRegistryInterface $themes)
    {
    }

    public function handle(Request $request): Response
    {
        $theme = (string) $request->attribute('route.theme', '');
        $file = (string) $request->attribute('route.file', '');

        if ($theme === '' || $file === '' || !$this->themes->has($theme)) {
            throw HttpException::notFound();
        }

        // Belt and braces: the route pattern already forbids separators, but a rejected request
        // here costs nothing and a path that escapes the assets directory costs everything.
        if (str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
            throw HttpException::notFound();
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!isset(self::TYPES[$extension])) {
            throw HttpException::notFound();
        }

        $path = $this->themes->get($theme)->assetsPath() . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path) || !is_readable($path)) {
            throw HttpException::notFound();
        }

        $body = file_get_contents($path);

        if ($body === false) {
            throw HttpException::notFound();
        }

        $modified = (int) filemtime($path);

        return new Response($body, 200, [
            'Content-Type' => self::TYPES[$extension],
            'Content-Length' => (string) strlen($body),
            'Last-Modified' => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
            // Themes change when a site is redeployed, not minute to minute. An hour is a
            // compromise: long enough to matter, short enough that a fix is not stuck behind a
            // day-long cache. Templates add ?v= so an edit is picked up immediately anyway.
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
