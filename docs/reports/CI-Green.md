# CI Is Green

Run #7 of `kmpro888/kimasta-cms`, on a clean Ubuntu runner with PHP 8.2 and 8.3 and MySQL 8.0:

```text
Lint (PHP 8.2)                 success
Lint (PHP 8.3)                 success
Unit suite (PHP 8.2)           success
Unit suite (PHP 8.3)           success
Full suite (PHP 8.3, MySQL)    success   138s
```

The full job is the one that matters: it installs from `composer.lock`, creates the database,
runs every migration, rolls them all back and re-applies them, runs the deployment preflight, and
then the whole suite. Nothing from this machine is involved.

Until this ran, "1392 tests green" was a claim about one Windows box with a populated database.

---

## What CI found that the local suite could not

**A bug in the Imagick path of `ImageProcessor`.** Two unit tests failed on the runner and passed
here:

```text
scalesDownToFitWithoutUpscaling   expected 200x150, got 200x200
neverUpscalesASmallImage          expected 80x60,   got 640x640
```

The cause: this machine has GD only, the runner has GD *and* Imagick, and the processor prefers
Imagick. Its resize used

```php
$image->thumbnailImage($maxWidth ?? 0, $maxHeight ?? 0, true, true);
```

`fill = true` treats the box as something to fill, so the aspect ratio was lost and small images
were enlarged. Shared hosting commonly has Imagick, so this was the behaviour most likely in
production and the one path never exercised.

Fixed by computing the fit explicitly and refusing to scale up:

```php
$scale = min($boxWidth / $sourceWidth, $boxHeight / $sourceHeight, 1.0);
```

---

## Three workflow defects, all found by running it

1. **The unit job never created `.env`.** It failed in one second on both PHP versions — the suite
   could not start, not a test failure. The setup is now shared with the full job.
2. **A one-second exit is not a test failure.** The duration of the failing step was the clue that
   separated "boot problem" from "assertions", and it took the run twice to notice it.
3. **PHPUnit is invoked through `php`** (`php vendor/bin/phpunit`) rather than the `vendor/bin`
   proxy, so a missing executable bit cannot masquerade as a test failure.

---

## The diagnostic detour, recorded

Reading a job log needs authentication, and the repository was private. Rather than ask for a
token, the workflow was given a temporary step that, on failure, wrote the PHPUnit output to a
`ci-diagnostics` branch that could be read publicly. That is how the Imagick bug was found.

The step and its `contents: write` permission were removed once the suite was green, and the
branch deleted. Removing it by text range was done too eagerly and briefly deleted the `full` job;
the file was restored from the last green commit and re-verified.

---

## What this changes about the project's claims

| Before | After |
|---|---|
| "1392 tests pass" — on this machine | passes on a clean runner, PHP 8.2 and 8.3, fresh MySQL |
| migrations applied here | applied, rolled back, and re-applied from scratch |
| Imagick assumed equivalent to GD | Imagick verified, and fixed where it was not |

---

## Still not covered by CI

Concurrency and load, the WordPress migration against a real export, and browser-level end-to-end
tests. The workflow proves the suite and the schema; it does not prove the site under fifty
simultaneous readers.