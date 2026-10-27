<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Http;

use CMS\Core\Filesystem\LockInterface;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\RedirectResponse;
use CMS\Core\Http\Response;
use CMS\Modules\Cache\Contracts\PageCacheInterface;
use CMS\Modules\Cache\Entity\CacheDependency;
use CMS\Modules\Content\Contracts\ContentQueryServiceInterface;
use CMS\Modules\Content\Contracts\ContentTypeRegistryInterface;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Content\Exceptions\ContentNotFoundException;
use CMS\Modules\Seo\Contracts\RedirectServiceInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;
use CMS\Modules\Taxonomy\Contracts\TaxonomyServiceInterface;
use CMS\Modules\Theme\Contracts\TemplateResolverInterface;
use CMS\Modules\Theme\Contracts\ThemeRegistryInterface;
use CMS\Modules\Theme\Contracts\ViewRendererInterface;
use CMS\Modules\Theme\Entity\TemplateContext;
use CMS\Modules\Theme\Rendering\PageViewModelFactory;
use CMS\Shared\Contracts\SiteContextInterface;
use Throwable;

/**
 * The public front controller.
 *
 * This is the file that makes the rest of the platform observable over HTTP. It
 * implements the documented fast path:
 *
 *   request -> resolve site (compiled domain map, 0 queries)
 *           -> page cache (1 file read)
 *           -> HIT: send HTML, exit
 *
 * On a miss it resolves the URL through the registry, builds a ViewModel, renders
 * the theme and stores the result **with its dependencies declared**, which is what
 * gives the invalidation service something to act on later.
 *
 * A rendering failure is deliberately not cached: caching a broken page would
 * persist it until the next content edit.
 */
final class PublicPageController implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteResolverInterface $sites,
        private readonly PageCacheInterface $cache,
        private readonly ContentQueryServiceInterface $query,
        private readonly RedirectServiceInterface $redirects,
        private readonly ThemeRegistryInterface $themes,
        private readonly TemplateResolverInterface $templates,
        private readonly ViewRendererInterface $renderer,
        private readonly PageViewModelFactory $viewModels,
        private readonly TaxonomyServiceInterface $taxonomy,
        private readonly ContentTypeRegistryInterface $contentTypes,
        private readonly ?LockInterface $lock = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        // Fast path: host -> site id from the compiled domain map (0 queries).
        // The full SiteContext is only built on a cache miss, so a warm page never
        // reads the sites table.
        $siteId = $this->resolveSiteId($request);

        if ($siteId === null) {
            // An unknown host is a 404, never a silent default tenant.
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $path = $this->normalizePath($request->path());

        // Authentication and preview bypasses are decided upstream; this controller
        // is only reached for anonymous public requests, but the check is kept
        // explicit so a future caller cannot accidentally cache a private page.
        //
        // A page number or a search query changes the representation while the path
        // stays the same, so it must never share the path-keyed cache entry: doing
        // so served page 1 for `?page=2`.
        $eligible = !$this->hasVaryingQuery($request) && $this->cache->isEligible(
            $request->method(),
            $this->isAuthenticated($request),
            $this->isPreview($request),
            true
        );

        if ($eligible) {
            $cached = $this->cache->get($siteId, $path);

            if ($cached !== null) {
                return $this->html($cached->html, 'HIT');
            }
        }

        $site = $this->sites->resolveById($siteId);
        $theme = $this->themes->activeFor($siteId);

        // Redirect before content: a moved permalink should win over a 404.
        $redirect = $this->redirects->resolve($siteId, $path);

        if ($redirect !== null) {
            return new RedirectResponse($redirect['target_url'], $redirect['status_code']);
        }

        try {
            $content = $this->query->findByUrl($siteId, $path);
        } catch (ContentNotFoundException) {
            // Not a single page: try the listing kinds before giving up.
            $listing = $this->resolveListing($site, $theme, $path, $request);

            if ($listing !== null) {
                return $listing;
            }

            return $this->renderNotFound($site, $theme->name);
        }

        // Draft or otherwise non-public content must not be served anonymously.
        if (!$content->isPubliclyVisible()) {
            return $this->renderNotFound($site, $theme->name);
        }

        // One request per path should pay for the render and write the entry; the rest
        // would otherwise repeat identical work when the cache is cold. The wait is
        // bounded so a slow render cannot turn into a queue of stalled requests.
        $lockName = 'page:' . $siteId . ':' . sha1($path);
        $locked = $this->lock === null;

        if ($this->lock !== null) {
            try {
                $locked = $this->lock->acquire($lockName, 2);
            } catch (Throwable) {
                // A lock backend failure is not a page failure: fall through to an
                // uncached render instead of turning it into a 500.
                $locked = false;
            }
        }

        // Re-check after taking the lock. A wait here means another request was building this
        // page; it has now released, so its result is in the cache and rendering it again would
        // repeat identical work. Without this the lock only serialised the writes: every waiter
        // still re-rendered, which looked protected because it was orderly.
        if ($locked && $eligible) {
            $recheck = $this->cache->get($siteId, $path);

            if ($recheck !== null) {
                $this->releaseLock($lockName);

                return $this->html($recheck->html, 'HIT');
            }
        }

        try {
            $context = $content->type === 'page'
                ? TemplateContext::page()
                : TemplateContext::single($content->type);

            $view = $this->viewModels->forContent($site, $content, $context, $theme);

            // A reply box is a different view of the same article. The page is not cacheable when
            // `reply_to` is present (see `hasVaryingQuery`), so passing it here cannot leak into a
            // cached copy served to somebody else.
            $replyTo = (int) ($request->queryParam('reply_to') ?? 0);

            if ($replyTo > 0) {
                $view = $view->with(['reply_to' => $replyTo]);
            }

            $template = $this->templates->resolve($theme->name, $context);
            $html = $this->renderer->render($template, $view);

            // A request that could not take the lock still renders; it only skips the
            // write. A missed cache entry costs one duplicate render, while failing the
            // request would cost the visitor the page.
            if ($eligible && $locked) {
                $this->cache->put($siteId, $path, $html, $this->dependenciesFor($content, $siteId, $theme->name));
            }
        } catch (Throwable) {
            // Never cache a broken render; a transient failure must be retried on
            // the next request rather than frozen until the next edit.
            return $this->renderNotFound($site, $theme->name);
        } finally {
            if ($locked) {
                $this->releaseLock($lockName);
            }
        }

        return $this->html($html, 'MISS');
    }

    /**
     * Dependencies a cached page declares.
     *
     * These are exactly the keys the invalidation service reacts to. Omitting any
     * one of them would leave a stale page behind after the matching edit.
     *
     * @return list<CacheDependency>
     */
    private function dependenciesFor(Content $content, int $siteId, string $themeName): array
    {
        $dependencies = [
            CacheDependency::content((int) $content->id),
            // Any listing page shows this content, so a change must refresh them.
            CacheDependency::archive($siteId),
            CacheDependency::theme($themeName),
            CacheDependency::settings($siteId),
            CacheDependency::author($content->authorId),
        ];

        if ($content->featuredMediaId !== null && $content->featuredMediaId > 0) {
            $dependencies[] = CacheDependency::media($content->featuredMediaId);
        }

        return $dependencies;
    }

    private function renderNotFound(SiteContextInterface $site, string $themeName): Response
    {
        try {
            $theme = $this->themes->get($themeName);
            $view = $this->viewModels->forNotFound($site, $theme);
            $template = $this->templates->resolve($theme->name, TemplateContext::notFound());

            return $this->html($this->renderer->render($template, $view), 'BYPASS', 404);
        } catch (Throwable) {
            // No theme at all: still answer with a correct status rather than a
            // blank 500.
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    /**
     * Resolve a listing page: home, type archive, taxonomy archive, author archive
     * or search.
     *
     * Returns null when the path is not a listing, so the caller can render a 404.
     */
    private function resolveListing(
        SiteContextInterface $site,
        \CMS\Modules\Theme\Entity\ThemeManifest $theme,
        string $path,
        Request $request,
    ): ?Response {
        $siteId = $site->id();
        $page = max(1, (int) ($request->queryParam('page') ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $title = null;
        $criteria = ['published_only' => true, 'exclude_trash' => true, 'limit' => $limit, 'offset' => $offset];
        $term = null;
        $context = TemplateContext::archive('post');

        // Home: every published post.
        if ($path === '/') {
            $title = $site->name();
            $criteria['type'] = 'post';
            $context = TemplateContext::home();

            // Type archive: /blog for posts, /{type}s for anything registered.
        } elseif ($this->archiveTypeFor($path) !== null) {
            $type = (string) $this->archiveTypeFor($path);
            $title = ucfirst($type) . 's';
            $criteria['type'] = $type;
            $context = TemplateContext::archive($type);

            // Taxonomy archive: /term/{slug}
        } elseif (preg_match('#^/term/([A-Za-z0-9\-_]+)$#', $path, $matches) === 1) {
            $found = $this->taxonomy->findTermBySlug($siteId, $matches[1]);

            if ($found === null) {
                return null;
            }

            $term = ['name' => $found->name, 'slug' => $found->slug, 'description' => $found->description];
            $title = $found->name;
            // One criterion instead of every id: the repository pages this in SQL, so a
            // category holding 20,000 articles no longer builds a 20,000-placeholder IN list.
            $criteria['term_id'] = (int) $found->id;
            // Same type filter the blog archive uses. Without it the archive index cannot order
            // the page and MySQL filesorts ~25,000 rows to return ten.
            $criteria['type'] = 'post';
            $context = new TemplateContext(TemplateContext::TAXONOMY);

            // Author archive: /author/{id}
        } elseif (preg_match('#^/author/(\d+)$#', $path, $matches) === 1) {
            $authorId = (int) $matches[1];
            $title = 'Author';
            $criteria['author_id'] = $authorId;
            $context = new TemplateContext(TemplateContext::AUTHOR);

            // Search: /search?q=...
        } elseif ($path === '/search') {
            $query = $request->queryParam('q');
            $needle = is_string($query) ? trim($query) : '';

            if ($needle === '') {
                return null;
            }

            $title = 'Search: ' . $needle;
            $criteria['search'] = $needle;
            $context = new TemplateContext(TemplateContext::SEARCH);

            // Anything else is not a listing.
        } else {
            return null;
        }

        // Only the canonical representation of a listing is cached. A page number
        // or a search query shares the path but not the content.
        $cacheable = !$this->hasVaryingQuery($request);

        // The lock is taken *before* the render, not around the write. The expensive part of a
        // cold archive is building it, and a lock held only for the write lets every waiter
        // rebuild the same page — orderly, and useless.
        $lockName = 'page:' . $siteId . ':' . sha1($path);
        // No lock backend means nothing else can be competing: treat this request as the builder.
        $locked = $cacheable && $this->lock === null;

        if ($cacheable && $this->lock !== null) {
            try {
                $locked = $this->lock->acquire($lockName, 2);
            } catch (Throwable) {
                $locked = false;
            }

            if ($locked) {
                $recheck = $this->cache->get($siteId, $path);

                if ($recheck !== null) {
                    $this->releaseLock($lockName);

                    return $this->html($recheck->html, 'HIT');
                }
            }
        }

        try {
            $result = $this->query->paginate($siteId, $criteria);
            $view = $this->viewModels->forListing(
                $site,
                $theme,
                (string) $title,
                $result['items'],
                $page,
                $result['total_pages'],
                $term,
            );
            $template = $this->templates->resolve($theme->name, $context);
            $html = $this->renderer->render($template, $view);
        } catch (Throwable) {
            if ($locked) {
                $this->releaseLock($lockName);
            }

            return null;
        }

        if ($cacheable && $locked) {
            $this->cache->put($siteId, $path, $html, [
                CacheDependency::archive($siteId),
                CacheDependency::theme($theme->name),
                CacheDependency::settings($siteId),
            ]);

            $this->releaseLock($lockName);
        }

        return $this->html($html, $cacheable ? 'MISS' : 'BYPASS');
    }

    /**
     * Release a page lock, tolerating a missing backend.
     *
     * The controller runs with no lock backend in tests and on installations that have not
     * configured one, and the "no lock" case means "this request is the builder" — so the release
     * path has to be a no-op rather than a fatal error on a null backend.
     */
    private function releaseLock(?string $name): void
    {
        if ($name === null || $this->lock === null) {
            return;
        }

        try {
            $this->lock->release($name);
        } catch (Throwable) {
            // A failed release must not fail the page; the lock expires with its own timeout.
        }
    }
    /**
     * Does this request carry a parameter that changes the response while the path
     * stays the same?
     *
     * `page` beyond the first and any non-empty `q` qualify. Tracking parameters
     * such as `utm_*` deliberately do not, so a shared link still hits the cache.
     */
    private function hasVaryingQuery(Request $request): bool
    {
        $page = $request->queryParam('page');

        if ($page !== null && (int) $page > 1) {
            return true;
        }

        // A reply changes the page: the form carries a different parent and the article shows
        // "replying to". Caching it under the bare path would hand that state to the next reader.
        $replyTo = $request->queryParam('reply_to');

        if ($replyTo !== null && (int) $replyTo > 0) {
            return true;
        }

        $query = $request->queryParam('q');

        return is_string($query) && trim($query) !== '';
    }

    /**
     * Content type whose archive lives at this path, or null.
     */
    private function archiveTypeFor(string $path): ?string
    {
        if ($path === '/' || !preg_match('#^/([a-z][a-z0-9\-_]*)$#', $path, $matches) === 1) {
            return null;
        }

        $segment = $matches[1] ?? '';

        if ($segment === '') {
            return null;
        }

        if ($segment === 'blog') {
            return 'post';
        }

        // A registered type pluralised as `{type}s`.
        if (str_ends_with($segment, 's')) {
            $candidate = substr($segment, 0, -1);

            if ($this->contentTypes->has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveSiteId(Request $request): ?int
    {
        try {
            return $this->sites->siteIdFor($request->host());
        } catch (SiteNotFoundException) {
            return null;
        } catch (InvalidHostException) {
            return null;
        }
    }

    private function isAuthenticated(Request $request): bool
    {
        // The session is not started on the cache-first path, so an anonymous
        // request cannot be authenticated by definition. A caller that does have a
        // session passes it through an attribute.
        return $request->attribute('authenticated', false) === true;
    }

    private function isPreview(Request $request): bool
    {
        return $request->attribute('preview', false) === true
            || $request->queryParam('preview') !== null;
    }

    private function html(string $html, string $cacheStatus, int $status = 200): Response
    {
        $response = new Response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Cache' => $cacheStatus,
        ]);

        // Anonymous public pages are identical for every visitor, so a shared cache
        // may store them; the X-Cache header makes the behaviour observable.
        $response->setHeader('Cache-Control', 'public, max-age=0, must-revalidate');

        return $response;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
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
