# Wave 3 Tracking — Agent 04 Content + Agent 06 Media

Controller log. Records dispatch, method, defects and validation gates.

## Pre-flight

- Baseline before wave: `304 tests, 795 assertions` — green.
- Contracts available for consumption: `SiteContextInterface`, `ActorInterface`,
  `ClockInterface`, `ConfigInterface`, `EventDispatcherInterface`.
- Spec read: task pack lines 566–710 (Agent 04) and 824–960 (Agent 06);
  schema §17, §18, §19, §20, §21, §29, §30, §31, §68.
- Environment probe before starting: `gd=yes imagick=no exif=yes fileinfo=yes`,
  GD with WebP+JPEG+PNG.

## Dispatch decision — no sub-agents this wave

Wave 2 sub-agents both terminated mid-task with the same failure: *"tool calls
keep getting truncated"*, after producing partial modules. Re-dispatching into
the same failure would have cost another full cycle for another partial result.

Per orchestration rule G6 the controller is responsible for getting the wave
finished, so Wave 3 was implemented directly: spec reading, implementation,
tests and validation all performed in one context.

Consequence, stated plainly: Agents 04 and 06 ran **sequentially**, not in
parallel. Rule G2 (parallel within a wave) could not be honoured without the
sub-agent mechanism, and the wave was not held up artificially for it. Content
was completed and validated before Media began, which also removed any risk of
both touching `app/bootstrap.php` simultaneously.

## Gate results

| Gate | Result | Evidence |
|---|---|---|
| G4 full suite green | PASS | `OK (468 tests, 1314 assertions)` |
| No pre-existing test red | PASS | all 304 Wave 1–2 tests still pass |
| Migration forward | PASS | 2 content/media migrations applied on `line_cms_test` |
| Migration rollback | PASS | both dropped cleanly; suite green after re-apply |
| Dev DB migrated | PASS | `line_cms` now has contents, url_registry, media, media_variants |
| Frozen contracts untouched | PASS | Site/Actor/Session/REST interfaces unmodified |
| No `site_id = 1` | PASS | grep clean |
| No `SELECT *` | PASS | asserted by a performance test on profiled queries |
| No cross-module table access | PASS | Media asks Content via `MediaUsageCheckerInterface` |
| Container wiring | PASS | 11 module contracts + Core Router resolve |
| App still serves HTTP | PASS | `GET /api/v1/health` → `{"data":{"status":"ok"},"meta":{}}` |

## Defects found during implementation and test

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | `contents.published_at` received a `DateTimeImmutable`, PDO cannot bind an object | high — publishing via create blew up | formatted to `Y-m-d H:i:s` before binding |
| 2 | A slug change registered the new path but left the old one resolving | high — a stale permalink kept serving the entity and could later collide | `syncUrl($content, replace: true)` removes prior mappings first |
| 3 | Slug transliteration used `iconv//TRANSLIT`, which on Windows yields `caf-e` for `Café` | medium — public URLs would differ per OS | explicit Latin transliteration table; iconv only as fallback |
| 4 | `ContentRepositoryInterface::create()` took `site_id` inside `$attributes` | medium — a tenant boundary hidden in a free-form array | changed to `create(int $siteId, array $attributes)`; caught by a contract test |
| 5 | `SettingsService`-style stale-cache bug equivalent: none introduced this wave | — | — |
| 6 | Test-only: slug-order assertion compared an unsorted matrix to a sorted key list | low | sort both sides |
| 7 | Test-only: `published()` used on a draft in a listing assertion | low | use `paginate()` |

Defects 1 and 2 were real production bugs caught by tests, not by inspection:
defect 1 by the "publish at create" case, defect 2 by the permalink-move case.

## Notes for Wave 4

- `BlockCompilerInterface` is bound to `PlainBlockCompiler`. **Agent 11 (Blocks)
  must rebind it** rather than editing Content.
- `MediaUsageCheckerInterface` is bound by Content. If another module later
  references media, it must register its own checker or compose with the existing
  one; Media's `NullMediaUsageChecker` must never win over a real one.
- `ContentServiceInterface::setStatus()` is the low-level setter. Agent 08
  (Workflow) owns transition rules and must not duplicate them in Content.
- `url_registry` has a `canonical` column that is currently always 1. A redirect
  module can use it later without a schema change.

## Outcome

**PASSED.** Wave 3 accepted.

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (468 tests, 1314 assertions)
```

Baseline 304 → 468: **+164 tests**, none of the previous 304 broken.

| Suite | Tests |
|---|---|
| Unit | 192 |
| Integration | 177 |
| Contract | 26 |
| Security | 66 |
| Performance | 7 |
| **Total** | **468** |
