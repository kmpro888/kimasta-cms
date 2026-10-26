# Wave 2 Tracking — Agent 02 Multisite + Agent 03 Identity

Controller log. Not a deliverable; records dispatch, baseline and validation gates.

## Pre-flight (completed)

- Baseline before wave: `116 tests, 248 assertions` — green.
- Frozen contracts present and unchanged:
  `SiteContextInterface`, `ActorInterface`, `CacheInterface`, `ClockInterface`,
  `ConfigInterface`, `CsrfTokenManagerInterface`, `DomainEventInterface`,
  `EventDispatcherInterface`, `SessionInterface`, `TransactionManagerInterface`.
- `app/Modules/` empty; single migration
  `20261024000100_create_migrations_table.php`.
- Spec read: task pack sections 323-440 (Agent 02) and 442-562 (Agent 03).

## Dispatch

| Agent | Sub-agent id | Scope | Depends on |
|---|---|---|---|
| 02 Multisite | `8892ef5c-f907-4697-8520-54f0819587c8` | `app/Modules/Site`, `sites`/`site_domains`/`site_settings` | Agent 01 |
| 03 Identity | `978433ef-7b8a-4434-a68a-cb706e2c9187` | `app/Modules/Identity`, `users`/`roles`/`capabilities`/`role_capabilities`/`user_roles`/`password_resets` | Agent 01, Agent 02 |

Both dispatched in the same message (parallel, per rule G2). Agent 03 is allowed to
run concurrently but must consume `SiteContextInterface` only — it may not import
Agent 02's concrete classes (rule G5).

## Validation checklist (to run on arrival)

Per agent:
- [ ] Report matches the required `AGENT_REPORT` shape, every section present.
- [ ] Every written file is inside the agent's owned paths.
- [ ] No file under `app/Core/**` or `app/Shared/Contracts/**` modified.
- [ ] No frozen contract changed; no competing interface created.
- [ ] Migrations own tables only, have `down()`, run forward and roll back clean.
- [ ] No hardcoded `site_id = 1`; no `SELECT *` on hot paths; no cross-module table queries.
- [ ] No forbidden dependency (Redis/Node/Docker/queue/worker).
- [ ] Prepared statements only.
- [ ] Tests present and passing.

Wave-level (rule G4):
- [ ] Full suite green — `composer test`, all testsuites, not just the new files.
- [ ] No pre-existing test turned red.
- [ ] Migration forward + rollback verified from a clean test database.
- [ ] No `CONTRACT_CHANGE_REQUEST` open.

## Controller interventions

### Agent 03 — resumed (incomplete delivery)

Agent 03 went `inactive` after reporting *"Entities now match the documented schema. Let me
align the CapabilityCatalogue..."* — i.e. it stopped mid-edit, before writing services,
repositories, auth or any tests.

State found on disk at that moment:

```
app/Modules/Identity/Entity/User.php
app/Modules/Identity/Entity/Role.php
app/Modules/Identity/Entity/Capability.php
app/Modules/Identity/Support/CapabilityCatalogue.php
database/migrations/20261025000200_create_identity_tables.php
```

Assessment of what *was* delivered (quality gate, not acceptance):

- Entities are immutable, validate their own invariants, and expose `with*()` copies. Good.
- `User` treats `password_hash` as opaque and never compares plaintext. Correct.
- Migration matches `docs/02-Database-Schema.md`: `capability_key` column name, per-site
  unique `(site_id, email)` and `(site_id, username)`, hashed reset tokens, InnoDB, FKs
  with `ON DELETE CASCADE`, `down()` present and in reverse dependency order. Correct.
- `CapabilityCatalogue` is data-only (no logic), which matches the architecture rule that
  authorisation never hardcodes role names. Acceptable.

**Verdict: not a rejection — an incomplete run.** Per rule G6 the finding goes back to the
same sub-agent, not to a different one. Agent 03 was resumed with an explicit delivery list
(repositories, services, Actor, module contracts, service provider, tests) and the hard
rules restated.

### Agent 02 — status

Still `running` at the time of the Agent 03 intervention. Note: `app/Modules/Site/Contracts/
SiteResolverInterface.php` already exists and is syntactically valid, but it contains a
stray `n` character before `public function resolve(...)` on line 21 — a typo that will be
rejected at validation if it survives into the final delivery.

## Outcome

**PASSED.** Wave 2 accepted. Gate G4 satisfied.

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (304 tests, 795 assertions)
```

Baseline was 116 tests. Wave 2 added 188 tests and broke none.

### Final gate results

| Gate | Result | Evidence |
|---|---|---|
| G4 full suite green | PASS | 304/304, no skips/warnings/risky |
| No pre-existing test red | PASS | original 116 still pass |
| Migration forward | PASS | `bin/migrate.php` on `line_cms_test`, 3 applied |
| Migration rollback | PASS | `bin/migrate.php rollback`, all module tables dropped; suite green after re-apply |
| Frozen contracts untouched | PASS | all 10 mtime 14:47, before wave start |
| No `site_id = 1` | PASS | grep clean across both modules |
| No `SELECT *` | PASS | single grep hit is a docblock sentence, verified |
| No cross-module table access | PASS | Site references no identity table, Identity references no site table |
| No forbidden dependency | PASS | composer.json unchanged; no Redis/Node/ES/Laravel |
| Container wiring | PASS | all 10 module contracts + Core Router resolve |

### Coordination failure and handover

Both sub-agents terminated mid-task ("tool calls keep getting truncated"). Rather
than re-dispatching into the same failure, the controller completed the
implementation directly:

- Agent 02 stopped after `SiteResolverInterface` + the site migration. Remaining
  work (host normaliser, domain map, 3 repositories, resolver, 2 services,
  provider) was written by the controller.
- Agent 03 stopped after 4 entity/support files. Remaining work (4 repositories,
  5 services, Actor, ActorProvider, NativeSession, provider) was written by the
  controller.

The sub-agents' contracts, entities, exceptions and migrations were kept as
delivered — they were reviewed and judged sound (immutable entities with
invariant checks, typed exceptions, correct multisite unique keys, reversible
migrations). No file was rewritten for style.

### Defects found during validation

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | `Application::boot()` dropped `CoreServiceProvider` whenever any module provider was registered | **critical** — would have removed router, logger and DB factory from every boot | Core now always registered first via `array_unshift` |
| 2 | `SettingsService::set()` deleted the compiled cache without rebuilding it, contradicting the contract's "refresh" | medium — cache stayed cold until the next read | added `refresh()` that rebuilds after write |
| 3 | `HostNormalizer` accepted `example.com\0` because `trim()` stripped the NUL before the control-character check | **security** — input-smuggling class bug | control characters now rejected on the raw value before trimming |
| 4 | Named placeholder reused in one statement (`:identifier`, `:now`) | runtime error under native prepares | bound twice under distinct names; documented for later modules |
| 5 | `new ReflectionClass(...)->method()` in a contract test | PHP 8.3 syntax error (8.4 feature) | parenthesised |

Defect 1 was the most consequential: it was invisible to unit tests that build
services directly, and would only have surfaced when a module provider was added
to the real bootstrap — exactly the step this wave performed.

### Note for Wave 3

`Users` and `Site` are now wired and migrated. Agent 04 (Content) may depend on
`SiteContextInterface` and `ActorInterface` only; both are frozen and
implemented, so the parallel constraint from rule G5 no longer limits it.
