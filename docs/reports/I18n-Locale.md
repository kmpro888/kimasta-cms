# i18n: The Locale Setting Now Changes What People See

`default_locale` was written to `<html lang>` and used for date formatting, and every label stayed
English. A setting that appears to work and does not is worse than a setting that is absent — so
this item is proven by rendering pages, not by asserting that a value round-trips through the
database.

---

## What exists now

```text
app/Core/I18n/TranslatorInterface.php   locale(), t(key, params), has(key)
app/Core/I18n/Translator.php            catalogue lookup with a fallback chain
lang/en.php                             21 keys, the reference language
lang/id.php                             21 keys, Indonesian
```

`ViewModel::t()` makes a translation available to every template, theme and admin alike, and
`ViewModel::with()` carries the translator into the layout — the place where the navigation lives.

**The fallback chain is the point**: locale → site default → the key itself. A half-translated site
shows English where it has no translation and `nav.content` where it has no key at all. Never an
empty menu, never an exception in the middle of a page render.

---

## What is translated

| Area | Strings |
|---|---|
| admin navigation | Dashboard, Content, Media, Notifications, Comments, People, Redirects, Ads, Plugins, Settings, Sign out |
| public comment chrome | the counts, "Leave a comment", the field labels, the submit button, the moderation note, "No comments yet", "Comments are closed" |

`<html lang>` follows the same setting, so the document language and the words agree.

---

## What is **not** translated — the honest scope

1. **Page titles.** An admin screen still shows `<h1>People</h1>` while its menu entry says
   `Pengguna`, because controllers pass a title string. The title is the first thing a user reads,
   so this is the most visible gap.
2. **Flash and validation messages.** "Saved.", "That is the last administrator." and field errors
   are English.
3. **Content.** Post titles, bodies and taxonomy names are whatever the editor wrote.
4. **Theme templates beyond the comment partial** — archive labels, the 404 page.
5. **No translation interface.** Catalogues are PHP files edited by a developer.

The report says this because "i18n implemented" would be a lie: what exists is the machinery plus a
first slice of strings, and the gap is enumerated above.

---

## The tests

| | |
|---|---|
| translator | lookup, regional fallback (`id_ID` → `id`), missing key returns the key, missing translation falls back, parameter interpolation, invalid locale falls back, `has()`, empty locale |
| catalogue coverage | every key a view uses exists in English; every locale has the same keys as English; no dead keys; no accidental copy of the reference language |
| rendering | `id` changes the admin navigation **and** the public comment chrome, `en` restores it, an unsupported locale falls back without blanking the page, and no raw keys leak into markup |

The dead-key test is what keeps this from rotting: a catalogue that grows keys nobody uses is a
catalogue nobody trusts.

```text
php vendor/bin/phpunit --filter 'TranslatorTest|CatalogCoverageTest|LocaleRenderingTest'
OK (17 tests, 43 assertions)
```

Two keys are identical in both languages (`nav.media`, `nav.plugins`) and are allow-listed by name,
so an accidental copy-paste still fails while genuine cognates do not.

---

## Files

```text
app/Core/I18n/TranslatorInterface.php              NEW
app/Core/I18n/Translator.php                       NEW
lang/en.php, lang/id.php                           NEW  21 keys each
app/Modules/Theme/Entity/ViewModel.php             t(), locale(); `with()` keeps the translator
app/Modules/Admin/Http/AdminController.php         translator from the site's locale
app/Modules/Theme/Rendering/PageViewModelFactory.php  translator for single, archive and 404
app/Modules/Admin/Resources/views/layout.php       navigation labels
themes/default/partials/comments.php               comment chrome
tests/Unit/Core/TranslatorTest.php                 NEW  8 tests
tests/Contract/I18n/CatalogCoverageTest.php        NEW  4 tests
tests/Integration/Modules/Site/LocaleRenderingTest.php  NEW  5 tests
```

---

## Known limitations

1. **Page titles, flash messages and validation errors are still English** — see the list above.
2. **No plural rules.** "One comment" and ":count comments" are two keys, which works for English
   and Indonesian and would not for a language with more forms.
3. **No locale-specific date formats.** Dates use the site timezone and a fixed format; the locale
   does not change `j M Y`.
4. **No right-to-left support.** Nothing sets `dir`, and no RTL theme exists.
5. **No translation interface.** Catalogues are files.
6. **A locale change must flush the page cache** — the settings screen already does; a direct
   service call does not, which is why the test flushes explicitly.

---

## Ready to merge

yes
