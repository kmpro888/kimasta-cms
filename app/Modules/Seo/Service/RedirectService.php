<?php

declare(strict_types=1);

namespace CMS\Modules\Seo\Service;

use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Seo\Exceptions\InvalidRedirectException;
use CMS\Modules\Seo\Exceptions\RedirectLoopException;
use CMS\Modules\Seo\Repository\RedirectRepositoryInterface;

/**
 * Redirect management with loop protection.
 *
 * Lookup is one exact indexed hit on (site_id, source_path) — no regex, no scan —
 * because a miss happens on every 404 and must stay cheap.
 */
final class RedirectService implements RedirectServiceInterface
{
    private const ALLOWED_STATUS = [301, 302, 307, 308];

    public function __construct(private readonly RedirectRepositoryInterface $redirects)
    {
    }

    public function resolve(int $siteId, string $path): ?array
    {
        return $this->redirects->find($siteId, $this->normalizePath($path));
    }

    public function store(int $siteId, string $sourcePath, string $targetUrl, int $statusCode = 301): void
    {
        if (!in_array($statusCode, self::ALLOWED_STATUS, true)) {
            throw new InvalidRedirectException('Unsupported redirect status code.');
        }

        $source = $this->normalizePath($sourcePath);
        $target = trim($targetUrl);

        if ($source === '/') {
            throw new InvalidRedirectException('The site root cannot be redirected.');
        }

        if ($target === '') {
            throw new InvalidRedirectException('A redirect needs a target.');
        }

        if (!$this->isAcceptableTarget($target)) {
            throw new InvalidRedirectException('A redirect target must be a path or an http(s) URL.');
        }

        // Self-redirect is the simplest loop.
        if ($target === $source) {
            throw new RedirectLoopException();
        }

        // Walk the existing chain. The length cap also catches a cycle that does
        // not include the source path.
        $cursor = $this->normalizePath($target);
        $visited = [];

        for ($step = 0; $step < 10; $step++) {
            if ($cursor === $source) {
                throw new RedirectLoopException();
            }

            if (isset($visited[$cursor])) {
                throw new RedirectLoopException();
            }

            $visited[$cursor] = true;

            $next = $this->redirects->find($siteId, $cursor);

            if ($next === null) {
                break;
            }

            $cursor = $this->normalizePath($next['target_url']);
        }

        $this->redirects->put($siteId, $source, $target, $statusCode);
    }

    public function delete(int $siteId, string $sourcePath): bool
    {
        return $this->redirects->delete($siteId, $this->normalizePath($sourcePath));
    }

    public function listForSite(int $siteId): array
    {
        return $this->redirects->listForSite($siteId);
    }

    private function isAcceptableTarget(string $target): bool
    {
        if (str_starts_with($target, '/')) {
            // Reject protocol-relative, which is an off-site redirect in disguise.
            return !str_starts_with($target, '//');
        }

        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        // A full URL as a source is reduced to its path, so an admin pasting a URL
        // does not silently create a never-matching row.
        if (preg_match('#^https?://#i', $path) === 1) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';
        }

        $path = (string) preg_replace('/[?#].*$/', '', $path);
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
