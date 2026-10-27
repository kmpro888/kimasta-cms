# Content-Security-Policy and Public Security Headers

The interesting part of this item was not the header. It was the reason the policy could not be
strict until something else changed first: five admin forms carried
`onsubmit="return confirm(…)"`, and any policy that blocks inline script blocks those dialogs — the
delete buttons would have stopped asking.

So the work was: make the dialogs external, then make the policy strict enough to be worth having.

---

## What changed

**Inline handlers → one file.** The five confirm dialogs now carry `data-confirm="…"` and a single
delegated listener in `public/assets/admin.js`:

```js
document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) { event.preventDefault(); }
}, true);
```

Capture phase, so the confirmation runs before anything else can claim the submit.

**One middleware, outermost.** `SecurityHeadersMiddleware` is registered first in the global
pipeline, so a 404 or a 500 carries the headers too — the pages most likely to be probed are the
ones that failed.

```text
Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self';
  frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; font-src 'self' data:;
  style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
```

`X-Frame-Options` is sent beside `frame-ancestors` because they cover different browser generations,
and `form-action 'self'` stops a form posting to another origin.

---

## Two relaxations, named rather than hidden

1. **`style-src 'unsafe-inline'`.** The theme markup uses inline `style` attributes (the comment
   indentation) and two standalone pages — the comment confirmation and the password reset — carry a
   `<style>` block. Removing it would mean a stylesheet route for pages that exist for a single
   response. It is a real relaxation; it is on `style-src`, not `script-src`.
2. **`script-src 'unsafe-inline'` is opt-in.** An installation that injects a third-party analytics
   snippet needs it, because that snippet is inline. It is controlled by
   `security.csp_allow_inline_scripts`, defaults to **false**, and a test asserts that the default
   policy has exactly `script-src 'self'`.

---

## What the tests check

| | |
|---|---|
| headers present | a public page, a 404, an admin screen, and the API |
| policy content | the directives the report claims, and `script-src 'self'` **without** `'unsafe-inline'` |
| no inline script | six rendered pages are scanned for `on*=` attributes and for `<script>` without `src` |
| the behaviour survived the move | the admin shell loads `/assets/admin.js`, and a rendered ad form carries `data-confirm` |
| the opt-in is visible | `fromConfig(true)` produces `script-src 'self' 'unsafe-inline'` |

The third row is the one that keeps this honest: a policy is only as good as the markup it governs,
and the scan fails if a future view reintroduces an inline handler.

```text
php vendor/bin/phpunit --filter SecurityHeadersTest
OK (8 tests, 35 assertions)
```

---

## Files

```text
app/Core/Http/Middleware/SecurityHeadersMiddleware.php   NEW
app/Core/Bootstrap/CoreServiceProvider.php               registered outermost
public/assets/admin.js                                   NEW  the delegated confirm handler
app/Modules/Admin/Resources/views/layout.php             loads the file
app/Modules/Admin/Resources/views/{ads,comments,content/revisions,plugins,users}/*.php
                                                         onsubmit -> data-confirm
tests/Security/Core/SecurityHeadersTest.php              NEW  8 tests
```

---

## Known limitations

1. **`style-src 'unsafe-inline'` remains.** Inline `style` attributes in the theme and two
   standalone pages keep it. Moving them to a stylesheet is a separate change.
2. **Analytics needs the opt-in.** With `script-src 'self'` an enabled third-party snippet is
   blocked; the operator must set `security.csp_allow_inline_scripts=true`, which loosens the whole
   site rather than allow-listing one provider. A nonce per request would be the better design.
3. **No `Strict-Transport-Security`.** It is meaningless over plain HTTP, and this installation runs
   on HTTP; adding it behind TLS is a deployment step, not a code default.
4. **No `Cross-Origin-Embedder-Policy` / `-Opener-Policy`.** Neither is needed for anything here,
   and both are easy to add wrongly.
5. **CSP violations are not reported.** No `report-uri`, so a blocked script is invisible in
   production; the test suite catches the causes instead.
6. **The confirm dialogs are not covered by a browser test.** `window.confirm` cannot be answered
   from `--dump-dom`; the delegation is covered by reading the markup, not by clicking.

---

## Ready to merge

yes
