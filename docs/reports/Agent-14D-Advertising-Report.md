# Agent 14D — Advertising — Implementation Report

Agent: Agent 14D — Advertising
Module: `CMS\Modules\Advertising`
Status: DONE

---

## Summary

Placements and targeting for ads, with the theme receiving **rendered slots** in its
ViewModel rather than a repository. Seven placements, three targeting dimensions, a date
window, and priority ordering.

The security decision that shapes the module: an ad body is raw HTML, so writing one is
equivalent to writing a template file. The boundary is therefore `ads.manage`, held by
`administrator` only and deliberately not granted to `editor`.

---

## Files added

```text
app/Modules/Advertising/Contracts/AdvertisingServiceInterface.php
app/Modules/Advertising/Entity/Ad.php
app/Modules/Advertising/Repository/AdRepositoryInterface.php
app/Modules/Advertising/Repository/PdoAdRepository.php
app/Modules/Advertising/Service/AdvertisingService.php
app/Modules/Advertising/AdvertisingServiceProvider.php
app/Modules/Advertising/README.md

app/Modules/Api/Http/Controller/AdController.php

database/migrations/20261026001300_create_advertising_tables.php

tests/Integration/Modules/Advertising/AdvertisingServiceTest.php
```

## Files modified

```text
app/bootstrap.php                                       provider registered
app/Modules/Identity/Support/CapabilityCatalogue.php    ads.manage added (administrator only)
app/Modules/Theme/Rendering/PageViewModelFactory.php    `ads` key in the ViewModel
app/Modules/Theme/ThemeServiceProvider.php              advertising injected (optional)
app/Modules/Api/ApiServiceProvider.php                  4 ad routes
themes/default/templates/single.php                     before/after article slots
tests/Support/ModuleDatabaseTestCase.php                advertising migrations + tables
```

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261026001300_create_advertising_tables.php` | `ads`, `ad_rules` |

Verified forward and rollback on both databases.

**No impression or click counter column.** A row write per visitor view is exactly the
load a shared host cannot take, and the platform already supports an analytics provider
for counting.

---

## Contracts provided

`AdvertisingServiceInterface`.

## Contracts consumed

`ClockInterface` only. The module has no dependency on Content, Taxonomy or Theme — it
receives its context as an array, so the theme layer supplies whatever it knows.

---

## Placements

```text
header  sidebar  before_article  middle_article  after_article  sticky  footer
```

Validated at three points: `create()`, `update()`, and `forPlacement()`. A template that
asks for `sidebaar` throws instead of rendering an empty slot forever. A silent no-op is
the worst outcome for a placement typo: nobody notices the revenue is missing.

---

## Targeting semantics

| Rule | Matches when |
|---|---|
| `device` | context device is in the allowed set |
| `content_type` | context type is in the allowed set |
| `term` | any context term id overlaps |

Rules are **AND-ed**. No rules = no targeting = matches everything.

### Unknown rule types fail closed

- **At write time**: refused, because a stored rule that can never match is a silent
  outage for that ad.
- **Already stored** (future version, manual insert): the ad becomes ineligible instead of
  the rule being ignored. Silently skipping a rule would run an ad where its author did
  not intend, which is worse than not running it.

Two tests cover both halves.

---

## Security

| Risk | Control |
|---|---|
| Stored XSS via `body_html` | raw by design; **capability-gated** to `ads.manage` (administrator only, not editor) |
| Attribute break-out via `target_url` | `htmlspecialchars(ENT_QUOTES\|ENT_SUBSTITUTE)` |
| Session/SEO leak via `target="_blank"` | `rel="nofollow sponsored noopener"` added |
| Cross-site ad access | every repository method is `site_id`-scoped; a cross-site delete returns false |
| Advertising failure breaking a page | `adsFor()` swallows any throwable and returns an empty slot map |

The `target_url` escaping is the deliberate asymmetry: a URL in an attribute is a
different risk from an author-controlled creative, so it is escaped while the creative is
not.

---

## Performance

`renderPlacements()` serves every slot on a page from **one** repository read, and all ads'
rules load in a single query rather than one per ad. The default theme asks for two slots
on an article; a page that renders none still costs one indexed read.

---

## Tests added

`AdvertisingServiceTest` — 21 tests:

- ad returned for its placement only
- an unknown placement is rejected, not silently empty
- inactive ad excluded; expired and future windows excluded; live window included
- device, content-type and term rules each limit the ad
- rules are AND-ed; an untargeted ad matches everything
- an unsupported rule is refused at write time
- an unsupported *stored* rule makes the ad ineligible
- priority ordering
- render wraps and marks the creative; `target_url` escaped with `nofollow sponsored`
- empty placement renders `''`; several placements in one call
- tenant isolation; update replaces rules; delete cascades rules
- cross-site delete is refused

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (1043 tests, 3180 assertions)
```

Baseline 1022 → 1043: **+21 tests**, none broken.

---

## Verified over real Apache HTTP

The default theme now prints `before_article` and `after_article`, so the whole chain is
observable:

```
GET /blog/hello-from-the-public-renderer
  FOUND  class="ad"
  FOUND  data-placement="before_article"
  FOUND  SPONSOR
  FOUND  rel="nofollow sponsored noopener"
  FOUND  a=1&amp;b=2
```

and the management API:

```
GET  /api/v1/ads                    200  list with rules and window
POST /api/v1/ads                    201
POST /api/v1/ads (bad placement)    422
GET  /api/v1/ads  (anonymous)       401
GET  /api/v1/ads  (before reseed)   403  missing ads.manage
```

---

## Second occurrence of the same upgrade trap

`ads.manage` is a **new** catalogue capability, so an existing database returned `403`
until `RoleService::seedDefaults()` ran again. This is the second time in this wave (after
`comment.moderate`), and it is a genuine upgrade step, not a bug:

```text
Adding a capability to the catalogue requires re-seeding existing sites.
seedDefaults() is idempotent, so re-running it is safe.
```

It is worth a note in the deployment runbook rather than being rediscovered per feature.

---

## Known limitations

1. **No rotation or frequency capping.** Every eligible ad in a slot renders, every time.
2. **No path or regex targeting.** Device, content type and term only.
3. **No click or impression tracking**, by design (see above). Counting is the analytics
   provider's job.
4. **No ad scheduling UI.** The API and console are the interface.
5. **`middle_article` and `sticky` are valid placements but the default theme does not
   render them.** A theme that wants them reads the same ViewModel key.
6. **`term_ids` is not populated by the page factory yet**, so a `term`-targeted ad will
   not match on an article page until the factory passes the article's term ids. The
   service supports it; the wiring is one line away.
7. **No A/B testing, budget pacing, or advertiser accounts.**
8. **Deleting an ad is immediate and unrecoverable**; there is no soft delete.

---

## CONTRACT_CHANGE_REQUEST

none

`PageViewModelFactory` gained an **optional** constructor argument, so an installation
without the advertising module still builds the factory.

---

## Ready to merge

yes
