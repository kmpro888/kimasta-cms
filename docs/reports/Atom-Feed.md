# Atom Feed

The site had an RSS feed and no Atom feed. RSS 2.0 has no stable per-entry `id` and no explicit
`updated`, which is what a reader needs to resubscribe without showing every item again. Atom is the
format for that, and it was simply absent.

---

## What it does

`/atom.xml` serves Atom 1.0:

```xml
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>…</title>
  <id>https://host/</id>
  <updated>…</updated>
  <link rel="self" type="application/atom+xml" href="https://host/atom.xml"/>
  <link rel="alternate" type="text/html" href="https://host/"/>
  <author><name>…</name></author>
  <entry>
    <title>…</title>
    <id>https://host/blog/slug</id>
    <link rel="alternate" type="text/html" href="…"/>
    <published>…</published>
    <updated>…</updated>
    <summary>…</summary>
  </entry>
</feed>
```

**One query, two formats.** `generateAtom()` runs the same `paginate()` call as the RSS generator, so
the two feeds cannot disagree about what is on the site. Both are written as files — a crawler or a
reader polling every fifteen minutes must not cause a database query — and `bin/cron.php` rebuilds
both.

`Content-Type: application/atom+xml`, `Cache-Control: public, max-age=900`, the same revalidation
window as RSS.

The document head advertises it:

```html
<link rel="alternate" type="application/atom+xml" href="/atom.xml">
```

---

## The bug the content-type assertion caught

The first version registered `$router->get('/atom.xml', FeedController::class, 'atom', 'seo.atom')`
and served **RSS** at that URL. A route whose handler is a class name only ever gets `handle()`
called — the fourth argument was silently ignored, `handle()` ran, and `/atom.xml` returned the RSS
file with the RSS content type.

The fix is a dedicated `AtomFeedController`. The test asserts the content type and parses the XML, so
a route that serves the wrong format cannot pass by looking plausible.

---

## The tests

| | |
|---|---|
| served | `200`, `application/atom+xml; charset=utf-8` |
| valid XML | parsed with `DOMDocument` |
| structure | one `feed`, an `id`, an `updated`, and at least one entry |
| every entry | an `id`, an `updated`, and an alternate link |
| discovery | the article head advertises the feed |

```text
php vendor/bin/phpunit --filter 'RevisionDiffAndAtomTest'
OK (5 tests, 23 assertions)
```

---

## Files

```text
app/Modules/Seo/Service/FeedService.php        generateAtom(), atomPathFor(), readAtom(), renderAtom()
app/Modules/Seo/Http/AtomFeedController.php    NEW  (a class-name handler only gets handle())
app/Modules/Seo/SeoServiceProvider.php         route + binding
themes/default/partials/head.php               the discovery link
bin/cron.php                                   rebuilds Atom with RSS
app/Modules/Seo/Http/FeedController.php        the dead `atom()` method removed
```

---

## Known limitations

1. **Summary, not full content.** Entries carry `<summary>` with the excerpt; `<content type="html">`
   would put the whole article in the feed file.
2. **No pagination or `rel="next"`.** The feed is the latest N posts, as RSS already was.
3. **No per-category Atom feeds.**
4. **`updated` equals `published`.** An edited article does not advertise the edit; the entry id is
   stable, but the timestamp does not move.
5. **No conditional GET** (`If-None-Match` / `If-Modified-Since`) — the file is served whole.
6. **Comments and media are not in the feed.**

---

## Ready to merge

yes
