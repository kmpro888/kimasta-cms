# Accessibility Audit

The audit is a test suite, not a checklist in a document. It runs against the rendered HTML of every
public page and every admin screen, and it fails the build when a page breaks a rule that can be
verified mechanically.

There is no axe-core here: that needs Node, and this project must not require it. The checks below
are the ones that can be decided from the DOM without ambiguity — and they are the ones people
actually ship broken.

---

## Pages audited

| | |
|---|---|
| public | `/`, `/blog`, `/search?q=…`, a missing page (the themed 404), and an article page |
| admin | sign-in, dashboard, content, media, notifications, notification settings, redirects, plugins, ads, settings, people, comments |

The admin pages are fetched **signed in** — the audit logs in through the real form, with the real
CSRF token, and carries the session cookie. An audit that only looks at the sign-in page proves the
sign-in page.

---

## Checks

| Check | What it catches |
|---|---|
| `lang` | a document with no language declared |
| `title` | a page with no title |
| `h1` | zero or several `<h1>` |
| `heading-order` | `h2` followed by `h4` |
| `main-landmark` | no `<main>`, or two of them |
| `nav-landmark` | an admin screen with no navigation |
| `img-alt` | an image with no `alt` attribute (empty is valid — absent is not) |
| `control-name` | an input, select or textarea with no label, no `aria-label`, no `title` |
| `accessible-name` | a link or button with no text and no `aria-label` |
| `aria-labelledby` / `aria-describedby` | a reference to an id that does not exist |
| `duplicate-id` | the same id twice, which breaks label and aria references |
| `tabindex` | a positive `tabindex`, which reorders focus against reading order |
| `table-headers` | a table with no `<th>` |
| `skip-link` | an in-page link pointing at an id that does not exist |

`placeholder` is deliberately **not** accepted as a label: it disappears as soon as the user types.

---

## Result

```text
php vendor/bin/phpunit --testsuite Accessibility
OK (17 tests, 51 assertions)
```

Seventeen pages, no violations. That was the first run's result, which is exactly when a green
audit should be distrusted — so the audit was proven able to fail: an `<img>` without `alt` added to
the admin layout produced

```text
/admin [img-alt] an image has no alt attribute: /probe-no-alt.png
/admin/content [img-alt] an image has no alt attribute: /probe-no-alt.png
/admin/media [img-alt] an image has no alt attribute: /probe-no-alt.png
```

and the checks were then tightened once more (dropping `placeholder`, adding landmarks) with no new
violations.

---

## What this audit does **not** check

Stated plainly, because an accessibility claim is easy to overstate:

1. **Colour contrast.** Needs computed styles and a rendering engine; the thresholds are numeric
   (4.5:1 for body text) and a DOM parse cannot see them.
2. **Focus visibility and order.** `tabindex` is checked for the anti-pattern, but whether the focus
   ring is visible, and whether tab order follows reading order, needs a keyboard.
3. **Screen-reader phrasing.** "Reads sensibly" is not a mechanical property. A correctly labelled
   button can still be labelled badly.
4. **Keyboard traps and modal behaviour.**
5. **Zoom and reflow** at 200% / 320px width.
6. **Motion.** No animation audit; the site has almost none.
7. **PDF, video, and third-party embeds.** None are used.
8. **Language of parts** (`lang` on a foreign phrase).

These are the reasons the report says "no mechanical violations", not "accessible".

---

## Files

```text
tests/Accessibility/AccessibilityAuditTest.php   NEW  17 tests
phpunit.xml                                      an "Accessibility" suite
```

No production file changed: the pages already passed. That is the finding, and it is reported as
such rather than dressed up as a fix.

---

## Ready to merge

yes
