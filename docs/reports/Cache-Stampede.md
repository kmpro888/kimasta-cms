# Cache Stampede: A Lock That Did Nothing

The task asked for the stampede lock to be *proven*. Inspecting it first turned up something better
than a proof: the lock was real, and it protected nothing.

---

## What the code did

`PublicPageController` took a lock named `page:<site>:<sha1(path)>` with a two-second wait, and the
comment said exactly what it intended:

> One request per path should pay for the render and write the entry; the rest would otherwise
> repeat identical work when the cache is cold.

But after acquiring the lock, the controller **did not look at the cache again**. So:

- the winner rendered and wrote;
- every waiter acquired the lock in turn — and rendered again;
- the lock serialised the writes and deduplicated nothing.

A cold popular URL still cost one render per request. Orderly, and useless. The listing path was
worse: it rendered **before** taking the lock, so the lock guarded only the write.

---

## The fix

Two changes, both about *where* the lock sits rather than what it does:

1. **Re-check the cache after acquiring the lock.** A wait means somebody else was building this
   page; they have released, so their result is in the cache. Serving it is the whole point.
2. **Take the lock before rendering on the listing path**, not around the write.

A missing lock backend still means "this request is the builder", and releasing is a guarded no-op —
which is what the first version of the fix got wrong, and the cache tests caught it immediately.

---

## Proving it — and the first test that proved nothing

The first concurrency test fired ten simultaneous requests at a cold article and asserted that at
most two rendered. It passed.

It also passed **with the re-check disabled**, which made it worthless: rendering was fast enough
that later requests found the cache at the top of `handle()` and never reached the lock at all. It was
measuring scheduling, not deduplication.

The test that replaced it is deterministic:

1. the test **holds the lock** for the path;
2. ten requests are started, and they block on it;
3. the test publishes a **sentinel page** into the cache and releases the lock;
4. every response is asserted to be the sentinel, and to say `X-Cache: HIT`.

With the re-check removed, the bodies are rendered pages rather than the sentinel and the test fails:

```text
Every waiter must serve the published entry. Cache headers: MISS,HIT,HIT,HIT,HIT,HIT,HIT,HIT,HIT,HIT
```

Two further tests cover the read path: ten concurrent readers of a **warm** article are all `HIT`,
and the archive is served under concurrency without rebuilding per request.

---

## Files

```text
app/Modules/Theme/Http/PublicPageController.php   re-check after lock; lock before listing render;
                                                  null-safe releaseLock()
tests/Performance/CacheStampedeTest.php      NEW  3 tests, real concurrency via php -S + curl_multi
```

---

## Verified

```text
php vendor/bin/phpunit --filter CacheStampedeTest
OK (3 tests, 8 assertions)

mutation check (re-check disabled)
1 failure — the sentinel was not served
```

```text
php vendor/bin/phpunit
OK (1401 tests, 4431 assertions)
```

---

## Known limitations

1. **Ten concurrent requests, one machine.** This proves the mechanism, not a capacity ceiling; no
   test here says how many readers per second a host can serve.
2. **A waiter that exceeds the two-second lock wait renders anyway.** That is deliberate — failing
   the visitor would be worse than one duplicate render — so "exactly one render" is an expectation,
   not a guarantee, under a slow rebuild.
3. **The lock name is reproduced in the test** rather than exposed by the application; a public
   getter would be production surface added only for a test.
4. **No timing assertions.** Percentiles from this machine would be noise; the report states
   behaviour, and the measurements live in `Performance-Gate.md`.
5. **The listing test does not assert identical bodies**, only bounded misses and status 200: the
   deduplication proof is the deterministic article test.

---

## Ready to merge

yes
