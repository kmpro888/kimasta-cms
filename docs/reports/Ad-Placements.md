# Ad Placements: Four Printed, Three Named

The advertising module has had **seven** placements since it existed, and the theme printed **none**
of them. `PageViewModelFactory::adsFor()` rendered every placement and stored it in the view model
under `ads`; no template ever read that key. An operator could configure an ad in the admin and see
nothing on the site, forever, with no way to tell whether the ad was wrong or the theme was.

That is the defect this round closes — in both directions.

---

## Four placements are rendered now

| Placement | Where the theme prints it |
|---|---|
| `header` | first thing inside `<main>`, on the article, the listing, the archive and the 404 |
| `before_article` | directly above the article body |
| `after_article` | directly below the article body, before the comments |
| `footer` | at the top of the site footer |

The slots go through `themes/default/partials/ad.php`, which prints nothing at all when a slot is
empty — not an empty wrapper, because a wrapper with no ad still takes space.

Listing pages needed one more fix: `ads` was only put in the view model for a single article, so the
page-level slots had nothing to print on `/blog`. The listing view model carries them now.

---

## Three placements are marked unsupported, with reasons

| Placement | Why |
|---|---|
| `sidebar` | the theme is single-column and has no sidebar region; printing there would mean inventing layout the theme does not have |
| `middle_article` | the article body is rendered from one HTML string, so there is no mid-body boundary to split at; splitting markup would break a block that spans paragraphs |
| `sticky` | the theme has a static header and no sticky container; a fixed overlay would be a different visual design, not a placement in this one |

`PlacementRegistry` is the single source of that inventory. The admin screen offers only the four,
and lists the three below the form with the reason — an option that silently does nothing is worse
than an option that is missing. A request that posts an unsupported placement is stored in a
supported one rather than in the phantom slot.

---

## The tests

| | |
|---|---|
| inventory | seven placements, four supported, three explained, disjoint sets, no placement undecided, every refusal has a reason |
| rendering | all four supported placements appear on the article, exactly one slot each |
| listings | the page-level slots appear on `/blog` |
| absence | `sticky`, `sidebar` and `middle_article` never print a marker |
| admin | only the supported four are offered as `<option>` values; the unsupported three are named and the panel says why |
| storage | posting `sticky` stores a supported placement |

```text
php vendor/bin/phpunit --filter PlacementRenderingTest
OK (6 tests, 36 assertions)
```

Writing the footer slot found a second, quieter bug: a partial receives only `$view`, not
`$renderer` or `$themeName`, so a nested partial call from inside `footer.php` raised an undefined
variable warning — which, with warnings converted to exceptions, turned every page into "Not found".
The footer slot renders inline now, and the comment in the file says why.

---

## Files

```text
app/Modules/Advertising/Support/PlacementRegistry.php   NEW
themes/default/partials/ad.php                          NEW  one slot
themes/default/templates/{index,archive,single,404}.php  the header slot
themes/default/templates/single.php                     before/after article slots
themes/default/partials/footer.php                      the footer slot, inline
app/Modules/Theme/Rendering/PageViewModelFactory.php    ads on listing pages too
app/Modules/Admin/Http/Controller/AdController.php      the registry decides what is offered
app/Modules/Admin/Resources/views/ads/index.php         the unsupported panel
tests/Integration/Modules/Advertising/PlacementRenderingTest.php  NEW  6 tests
```

---

## Known limitations

1. **Three placements are unsupported by this theme.** A theme with a sidebar or sticky header could
   support them; the registry is where that would be declared, but support is per-theme and only
   `default` exists.
2. **No impression or click tracking.** Nothing is counted, so nothing is measured; the PRD does not
   ask for it and it would be a write on every page view.
3. **No click-through URL handling beyond the stored `target_url`** — the operator writes the markup.
4. **Rules are coarse**: content type and taxonomy term, not schedule windows, geo or frequency.
5. **Ads live inside the page cache.** Changing an ad flushes the affected pages; there is no
   separate fragment cache, which is deliberate — a second cache layer on shared hosting is a second
   thing to invalidate wrongly.
6. **No HTML sanitisation of the markup.** Administrator-only by capability, and the screen says so.

---

## Ready to merge

yes
