# Test Suite Performance — Diagnosis

Question asked: why did `vendor\bin\phpunit` take about twenty minutes?

Nothing was measured before this investigation; the figure had simply been accepted. It is
measured now, and the cause was not what it looked like.

---

## Measurements

| Experiment | Result | Conclusion |
|---|---|---|
| ApiExtrasTest, xdebug on | 132.7 s | baseline |
| ApiExtrasTest, `xdebug.mode=off` | 139.1 s | **Xdebug is not the cause** |
| ApiExtrasTest, xdebug off + opcache CLI + JIT | 134.9 s | **opcache is not the cause** |
| app boot + container | 0.027 s | negligible |
| truncate 41 tables (`DELETE` + `ALTER AUTO_INCREMENT`) | 2.633 s | **the cause** |
| `RoleService::seedDefaults()` per site | 0.557 s | second contributor |
| truncate 41 tables (`TRUNCATE`) | 8.527 s | worse, rejected |
| truncate 41 tables (`DELETE` only) | **0.025 s** | fix |

Per integration test the harness paid ~3.2 s of setup, of which ~2.6 s was resetting the
auto-increment counter on every table. Over 566 integration tests that is roughly 22
minutes -- exactly the observed duration.

## Why `ALTER TABLE ... AUTO_INCREMENT = 1` is slow

It is a data-dictionary operation, not a row operation: MySQL takes a metadata lock and
re-registers the table's auto-increment state. Doing that 41 times per test is 41 dictionary
operations per test, and an empty table costs the same as a full one.

`TRUNCATE TABLE` looks like the obvious replacement -- it resets the counter as part of the
operation -- but it was measured at 8.5 s, three times *slower*, because InnoDB drops and
recreates the tablespace per table.

`DELETE` alone is 0.025 s: emptying 41 already-empty tables is trivial. The only thing lost
is the counter reset.

## The fix

`tests/Support/ModuleDatabaseTestCase.php` no longer resets `AUTO_INCREMENT`. It runs
`DELETE FROM` only, inside the existing `SET FOREIGN_KEY_CHECKS = 0` block.

Resetting the counter existed so ids would be "predictable within a test". The tests do not
depend on that: they use the ids they are handed. Monotonically growing ids across a run are
harmless, and the suite proves it.

## Result

| | Before | After |
|---|---|---|
| ApiExtrasTest (53 tests) | 132.7 s | **52.1 s** |
| Full suite (1114 tests) | 22 min 11 s | **6 min 17 s** |

Both runs: 1114 tests, 3487 assertions, green. A 3.5x speed-up with no test changed and no
coverage lost.

Per-test setup dropped from ~3.2 s to ~0.6 s, most of what remains being
`seedDefaults()`: 26 capability upserts plus six roles plus their grants, which has to run
because every test starts from emptied tables.

## What this tells us about the suite design

Two structural facts worth stating, because they bound how fast this suite can ever be:

1. **Every integration test rebuilds the world.** It empties 41 tables, seeds a role matrix,
   compiles a domain map and boots 18 providers. That is a deliberate trade -- tests share no
   state, so they run in any order and fail independently -- and it costs about 0.6 s each.
2. **The remaining cost is the seed, not the database.** If this needs to go faster, the next
   step is to stop re-seeding `capabilities` (global reference data, not tenant data) per
   test, not to optimise SQL further.

## How to keep the loop fast

| Goal | Command | Time |
|---|---|---|
| touched one module | `phpunit --filter 'SomeTest'` | seconds |
| no database involved | `phpunit --testsuite Unit` | ~1 s |
| full verification | `phpunit` | ~6 min |

Running the full suite after every small edit was the real waste in this session. The
diagnosis above fixes the constant; using `--filter` while iterating fixes the multiplier.
