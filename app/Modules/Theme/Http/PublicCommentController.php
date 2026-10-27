<?php

declare(strict_types=1);

namespace CMS\Modules\Theme\Http;

use CMS\Core\Http\HttpException;
use CMS\Core\Http\Request;
use CMS\Core\Http\RequestHandlerInterface;
use CMS\Core\Http\Response;
use CMS\Core\Security\RateLimiter;
use CMS\Modules\Comment\Contracts\CommentServiceInterface;
use CMS\Modules\Comment\Exceptions\CommentsClosedException;
use CMS\Modules\Comment\Exceptions\CommentNotFoundException;
use CMS\Modules\Content\Contracts\ContentServiceInterface;
use CMS\Modules\Content\Contracts\UrlGeneratorInterface;
use CMS\Modules\Site\Contracts\SiteResolverInterface;
use CMS\Modules\Site\Exceptions\InvalidHostException;
use CMS\Modules\Site\Exceptions\SiteNotFoundException;

/**
 * A visitor posting a comment.
 *
 * Public comments are anonymous: there is no reader account, so the form takes a name and stores
 * `user_id = 0` to mark a guest. Everything posted lands in the moderation queue as `pending` and
 * nothing appears on the site until a moderator approves it — which is the whole point of offering
 * the form, since unreviewed public text is an injection surface with extra steps.
 *
 * Three cheap defences, none of them a CAPTCHA:
 *
 *  - a **honeypot** field that only a script fills, answered with the same confirmation page so the
 *    script learns nothing;
 *  - a **rate limit** per address, so one machine cannot fill the queue;
 *  - the queue itself, because a human approves every comment.
 *
 * The response is a small page written here rather than a theme template, and the redirect is
 * deliberately absent: a confirmation for a POST is not a cacheable public page, and a theme that
 * had to supply a template for it would be a theme that cannot be swapped without breaking
 * commenting.
 */
final class PublicCommentController implements RequestHandlerInterface
{
    /** Comments allowed per address per window. */
    private const MAX_PER_WINDOW = 5;

    private const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly SiteResolverInterface $sites,
        private readonly CommentServiceInterface $comments,
        private readonly ContentServiceInterface $contents,
        private readonly UrlGeneratorInterface $urls,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $siteId = $this->sites->siteIdFor($request->host());
        } catch (SiteNotFoundException|InvalidHostException) {
            throw HttpException::notFound();
        }

        $contentId = (int) $request->input('content_id', 0);

        if ($contentId <= 0) {
            throw HttpException::notFound();
        }

        // The honeypot. Answer exactly as for a real comment so a script cannot tell it was
        // ignored, and store nothing.
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->confirmation($siteId, $contentId, 'pending');
        }

        if (!$this->limiter->attempt($this->limitKey($siteId, $request), self::MAX_PER_WINDOW, self::WINDOW_SECONDS)) {
            return $this->page(
                429,
                'Slow down',
                'Too many comments from this connection. Try again in a few minutes.',
                $this->backPath($siteId, $contentId),
            );
        }

        $name = trim((string) $request->input('author_name', ''));
        $body = (string) $request->input('body', '');

        try {
            // A reply names its parent. Zero and absent both mean a top-level comment.
            $parentId = (int) $request->input('parent_id', 0);

            $this->comments->submit($siteId, $contentId, 0, $body, $parentId > 0 ? $parentId : null, $name);
        } catch (CommentsClosedException) {
            return $this->page(
                409,
                'Comments are closed',
                'This article is not accepting comments.',
                $this->backPath($siteId, $contentId),
            );
        } catch (CommentNotFoundException) {
            throw HttpException::notFound();
        } catch (\InvalidArgumentException $exception) {
            return $this->page(422, 'That did not look right', $exception->getMessage(), $this->backPath($siteId, $contentId));
        }

        // Nothing to invalidate: a pending comment is not on any page yet.
        return $this->confirmation($siteId, $contentId, 'pending');
    }

    private function confirmation(int $siteId, int $contentId, string $state): Response
    {
        return $this->page(
            200,
            'Thank you',
            $state === 'pending'
                ? 'Your comment has been received and is waiting for a moderator. It will appear once it is approved.'
                : 'Your comment has been received.',
            $this->backPath($siteId, $contentId),
        );
    }

    private function page(int $status, string $title, string $message, string $backPath): Response
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . $e($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'max-width:38rem;margin:4rem auto;padding:0 1.25rem;line-height:1.6;color:#1c2128}'
            . 'h1{font-size:1.5rem;margin-bottom:.5rem}a{color:#2563eb}</style></head><body>'
            . '<h1>' . $e($title) . '</h1><p>' . $e($message) . '</p>'
            . '<p><a href="' . $e($backPath) . '">&larr; Back to the article</a></p>'
            . '</body></html>';

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Never cached: it is an answer to one submission, and a shared cache holding it would
            // hand it to a stranger.
            'Cache-Control' => 'no-store',
        ]);
    }

    private function backPath(int $siteId, int $contentId): string
    {
        try {
            $content = $this->contents->get($contentId, $siteId);

            return $this->urls->pathFor($content->type, $content->slug);
        } catch (\Throwable) {
            return '/';
        }
    }

    private function limitKey(int $siteId, Request $request): string
    {
        $address = (string) ($request->server('REMOTE_ADDR') ?? 'unknown');

        return 'comment:' . $siteId . ':' . md5($address);
    }
}
