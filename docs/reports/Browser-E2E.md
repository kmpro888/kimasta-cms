# Browser End-to-End Tests

The other suites assert on strings the application returned. This one starts the application behind
a real HTTP server, renders the page in headless Chrome, and asks the resulting **DOM** questions.

That closes the gap that produced two of this project's most embarrassing defects, both found by
looking at a screenshot rather than by a test: a public site with **no stylesheet** because the theme
asset URL 404'd, and a comment form whose inputs were **invisible** because the CSS used variables
the theme does not define. A string assertion passes in both cases.

---

## How it works

```text
php -S 127.0.0.1:<free port> -t public tests/E2E/router.php
chrome --headless --host-resolver-rules='MAP example.com 127.0.0.1' --dump-dom http://example.com:<port>/
```

- **The application runs as it does under Apache.** `router.php` gives the built-in server the same
  rule `.htaccess` has: a real file is served, everything else is one request to `public/index.php`.
- **Tenant resolution is real.** The application maps sites by host, so the browser is told to
  resolve the test site's own host (`example.com`) to the local server. Calling `127.0.0.1` directly
  returns 404, correctly — and that is what the first version did.
- **The DOM is asserted, not the source.** Chrome runs the page first, so a broken asset, a fatal
  error in an include, or an unlabelled control all show up.

---

## What it checks

| | |
|---|---|
| document | `<html lang>`, a `<title>`, a `<main>` landmark |
| leaks | no `Fatal error`, `Parse error`, `Warning:`, `Notice:`, `Deprecated:` in the markup |
| stylesheet | the linked CSS is fetched and is not empty — the "unstyled site" defect |
| images | every `<img>` has an `alt` attribute |
| controls | every visible `<input>` is referenced by a `<label for=…>` |
| article | content renders, `<article>` exists, the comment form is reachable |
| admin | the login screen renders, its fields are labelled, the admin CSS resolves |

Six tests, 47 assertions, about twenty seconds.

---

## Four defects in the harness itself, all found by running it

1. **`2>/dev/null` in the Chrome command.** POSIX syntax; `cmd.exe` fails on it, so the browser
   produced an empty DOM and every test failed at the first assertion.
2. **Pipes to the server kept the parent alive.** `proc_close()` waits for the child, so the suite
   passed and then hung forever. Output goes to a file now.
3. **`proc_terminate()` killed the shell, not the server.** On POSIX the command is prefixed with
   `exec` so the server replaces the shell; on Windows the process tree is killed with
   `taskkill /T`.
4. **Two static properties were never declared.** A PHP `Error` in `setUpBeforeClass` reports as one
   error for the whole class, which reads like a fixture problem rather than a typo.

Each one cost a round trip. They are recorded because the next person to add an end-to-end test will
meet the same four.

---

## Files

```text
tests/E2E/router.php              the rewrite rule for `php -S`
tests/E2E/BrowserE2ETest.php      the suite
phpunit.xml                       a new "E2E" suite
```

---

## Verified

```text
php vendor/bin/phpunit --testsuite E2E
OK (6 tests, 47 assertions)   19.6s

php vendor/bin/phpunit
OK (1398 tests, 4420 assertions)
```

---

## Known limitations

1. **No interaction.** There is no clicking, typing or navigation: `--dump-dom` renders once. Form
   submission is covered by the HTTP tests, not here.
2. **No visual assertion.** Layout is not diffed; a page can be structurally correct and ugly.
3. **Skipped without a browser.** The tests mark themselves skipped when Chrome is absent, which is
   honest but means a machine without Chrome proves less. CI has one.
4. **One browser.** Chrome only; nothing exercises Firefox or Safari behaviour.
5. **Three public paths and one admin screen.** Not every route; the invariant checks are the point,
   not coverage.

---

## Ready to merge

yes