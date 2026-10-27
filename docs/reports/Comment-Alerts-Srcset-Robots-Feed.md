# Three Gaps: Comment Alerts, Media Srcset, Robots and Feed

The three items left after the public-comment decision. All three were "the mechanism exists, the
consumer does not" again, which is the recurring shape of this project's gaps.

---

## 1. A comment now rings the moderators' bell

Public comments land in a queue, and nothing said so: a moderator had to visit the screen and hope.

The `comment.created` event already existed and was already dispatched. Three small changes made it
useful:

- **`NotificationServiceInterface::EVENTS`** gained `comment.created`, so the notification module
  listens to it.
- **Recipients come from `comment.moderate`**, so a role change immediately changes who is told —
  never a stored list. A staff member's own comment does not notify them.
- **The payload carries what the notification needs** (`site_id`, `author_name`, an `excerpt`), so
  the notification module never reads the comments table.

The bug worth recording: the first version produced nothing, and the cause was that the comment
event payload did not include `site_id`. The handler derives the tenant from the payload, saw `0`,
and returned early — silently. A manual dispatch in a test reproduced it, which is the only reason
it was found quickly.

The preferences screen already listed the events generically, so the new switch appeared there
without touching the view: `New comment awaiting moderation`, in-app on, email off.

---

## 2. The featured image now carries a `srcset`

The upload pipeline has always derived `thumbnail` (150), `medium` (640) and `large` (1280). Nothing
printed them: every visitor downloaded the full-size file. The feature was paid for and never
delivered.

```text
<img src="…/large….png"
     srcset="…thumbnail… 150w, …medium… 640w, …large… 1280w, …original… 1600w"
     sizes="(min-width: 48rem) 42rem, 100vw">
```

Decisions worth stating:

- **One format per `srcset`.** Only variants whose extension matches the original go in; a WebP
  mixed into a PNG list is a format switch disguised as a size switch, and a browser would apply it
  to all candidates.
- **Widths must differ.** Three variants of a 40-pixel image are one candidate, not three, so a
  small image gets no `srcset` at all rather than three identical entries.
- **The original is the largest candidate**, added only when it is wider than the biggest variant.

The frozen interface was **not** changed. `MediaServiceInterface` is marked frozen, so the read side
arrived as a companion contract, `MediaVariantQueryInterface`, implemented by the same
`MediaService` instance. Not a competing interface — a narrower one.

---

## 3. `robots.txt` and an RSS feed

```text
GET /robots.txt        200  text/plain
GET /feed              200  application/rss+xml
GET /feed.xml          200  same document
```

`robots.txt` is generated per site and names the sitemap with an absolute URL, as the specification
requires. It disallows `/admin`, `/api/` and `/comment` — the last because a crawler has no business
submitting forms.

The feed is a **generated artifact**, like the sitemap and for the same reason: a public document
that a reader polls should be a file read, not a query. Cron rebuilds it (`Feed: 2 item(s) across 1
site(s).`), and a request that finds no artifact builds one itself, so a fresh install has a working
feed without waiting for the clock. Twenty items, newest first, drafts excluded.

---

## Files

```text
app/Modules/Notification/Contracts/NotificationServiceInterface.php  comment.created in EVENTS
app/Modules/Notification/Service/NotificationService.php             recipients, title, body
app/Modules/Comment/Service/CommentService.php                       site_id + name + excerpt in the payload
app/Modules/Admin/Http/Controller/NotificationController.php         the switch's label
app/Modules/Media/Contracts/MediaVariantQueryInterface.php   NEW     read-only companion
app/Modules/Media/Service/MediaService.php                          variantsFor(), sorted
app/Modules/Media/MediaServiceProvider.php                          binding, same instance
app/Modules/Theme/Rendering/PageViewModelFactory.php                srcsetFor()
themes/default/templates/single.php                                 srcset + sizes
app/Modules/Seo/Http/RobotsController.php                    NEW
app/Modules/Seo/Service/FeedService.php                      NEW
app/Modules/Seo/Http/FeedController.php                      NEW
app/Modules/Seo/SeoServiceProvider.php                              routes, bindings, feedDirectory()
bin/cron.php                                                        feed job
tests/Integration/Modules/Comment/CommentNotificationTest.php NEW   6 tests
tests/Integration/Modules/Theme/FeaturedImageSrcsetTest.php   NEW   3 tests
tests/Integration/Modules/Seo/RobotsAndFeedTest.php           NEW   5 tests
```

---

## Verified

```text
/robots.txt   200  text/plain        Disallow: /admin, /api/, /comment + Sitemap
/feed         200  application/rss+xml   2 items, titles, links, pubDate
/feed.xml     200  same document
cron          Sitemap: 3 URL(s)   Feed: 2 item(s)
srcset        thumbnail/medium/large/original, one format, widths distinct
notification  guest comment -> "New comment awaiting moderation from A Reader"
```

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1364 tests, 4303 assertions)
```

---

## Known limitations

1. **No reply form on the feed.** Atom is not offered; RSS 2.0 only.
2. **The feed has no category or author elements.** Twenty newest posts, no filtering by taxonomy.
3. **`srcset` stays single-format.** A `<picture>` with a WebP source would let a modern browser take
   the smaller file; the variants exist, the markup does not.
4. **The comment notification has no digest.** Ten comments in an hour produce ten notifications.
5. **The notification is not emailed by default.** The switch exists and defaults to off, consistent
   with every other event.
6. **The feed is not purged on publish.** It is rebuilt by cron, so a new article can be up to one
   cron interval late in the feed while the page itself is immediate.

---

## Two detours worth recording

1. **PowerShell string replacement bit me twice more.** `"\n"` inside a double-quoted replacement
   wrote literal `\n` into PHP and CSS-touching commands; one produced a mangled line that commented
   out the rest of a statement. The `edit` tool was reliable every time it was used.
2. **A container oddity.** `$c->has(MediaVariantQueryInterface::class)` answered `true` to a probe
   while the factory's closure still received `null`. Rather than chase it, `srcsetFor()` falls back
   to the media service it already holds — the same object by construction — and the feature works.
   The oddity is noted, not explained.

---

## CONTRACT_CHANGE_REQUEST

none

`MediaServiceInterface` is frozen and was left untouched; the read side is a separate contract that
implements nothing it already promised. `NotificationServiceInterface` gained a constant entry.

---

## Ready to merge

yes
