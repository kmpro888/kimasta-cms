# Agent 14A — Plugin Platform — Implementation Report

Agent: Agent 14A — Plugin Platform
Module: `CMS\Modules\Plugin`
Status: DONE

---

## Summary

A plugin is one class with one method. Everything it contributes goes through a narrow
`PluginContext`, so a plugin cannot reach the container, a database connection, or
another module's repository — that is what "plugins must not edit core" means in
practice.

The acceptance criterion from the task pack is satisfied and is the central test: the
example plugin registers a **content type, taxonomy, block, action, filter, API route
and capability** with no change anywhere under `app/`.

---

## Files added

```text
app/Modules/Plugin/Contracts/HookRegistryInterface.php
app/Modules/Plugin/Contracts/PluginInterface.php
app/Modules/Plugin/Contracts/PluginManagerInterface.php

app/Modules/Plugin/Entity/PluginManifest.php

app/Modules/Plugin/Exceptions/InvalidPluginManifestException.php
app/Modules/Plugin/Exceptions/PluginNotFoundException.php
app/Modules/Plugin/Exceptions/PluginNotInstalledException.php

app/Modules/Plugin/Repository/PluginRepositoryInterface.php
app/Modules/Plugin/Repository/PdoPluginRepository.php

app/Modules/Plugin/Service/HookRegistry.php
app/Modules/Plugin/Service/PluginCapabilityRegistry.php
app/Modules/Plugin/Service/PluginContext.php
app/Modules/Plugin/Service/PluginManager.php
app/Modules/Plugin/Service/PluginMigrationRunner.php
app/Modules/Plugin/Service/PluginRouteHandler.php

app/Modules/Plugin/PluginServiceProvider.php
app/Modules/Plugin/README.md

app/Modules/Api/Http/Controller/PluginController.php

database/migrations/20261026001200_create_plugin_tables.php

plugins/example-plugin/plugin.json
plugins/example-plugin/Plugin.php
plugins/example-plugin/migrations/20261026000100_create_example_notes.php

tests/Integration/Modules/Plugin/PluginManagerTest.php
```

## Files modified

```text
app/bootstrap.php                                       provider registered
app/Modules/Identity/Contracts/ExtraCapabilityProviderInterface.php   NEW seam
app/Modules/Identity/IdentityServiceProvider.php        optional provider injected
app/Modules/Identity/Service/RoleService.php            merges contributed capabilities
app/Modules/Api/ApiServiceProvider.php                  5 plugin routes
tests/Support/ModuleDatabaseTestCase.php                plugin tables; tolerates missing tables
```

---

## Database migrations

| Migration | Tables |
|---|---|
| `20261026001200_create_plugin_tables.php` | `plugins`, `site_plugins`, `plugin_migrations` |

Verified forward and rollback on both the test and development database.

---

## Lifecycle

```text
discovered  a directory with a valid plugin.json
installed   its migrations have run; recorded in `plugins`
active      enabled for one tenant; recorded in `site_plugins`
```

Install and activation are separate because they are different decisions by different
people: running schema changes is an operator action, enabling a feature on a site is an
administrator action.

**Uninstall does not drop a plugin's data.** The record and activation rows are removed;
a table created by the plugin's migration survives. Dropping tables as a side effect of
a toggle is how people lose production data, and a test asserts the row survives.

## Migrations

Plugin migrations live in `plugins/{name}/migrations/*.php` and return an object with
`up()`/`down()` — the **same shape as core migrations**, so there is one migration idiom
in the project rather than two. Applied files are recorded per plugin, so a reinstall
does not re-run schema changes and a plugin can be upgraded independently of core.

## Actions and filters

Kept as separate methods, because using an action where a filter is needed is a common
silent bug:

| Kind | Contract | Return value |
|---|---|---|
| action | "something happened" | ignored |
| filter | "here is a value, you may change it" | replaces it |

Higher priority runs first, matching the domain event dispatcher — one ordering rule
across the platform. Every callback is recorded against its owning plugin, so
deactivating a plugin removes exactly its hooks and nothing else.

## The hard part: per-tenant isolation of plugin routes

Routes must be registered during `boot()`, before a tenant is known. Activation is per
site. Those two facts are in direct conflict, and resolving it wrongly would mean
**activating a plugin on Site A exposes its endpoints on every tenant**.

The decision is therefore deferred to request time: each plugin route is wrapped in
`PluginRouteHandler`, which resolves the host and answers **404** when the plugin is
inactive for that tenant. Not 403 — a 403 would confirm the endpoint exists.

Verified over HTTP:

```text
plugin active      GET /api/v1/example/notes/ping  → 200
plugin deactivated GET /api/v1/example/notes/ping  → 404
plugin reactivated GET /api/v1/example/notes/ping  → 200
```

Contributions other than routes (blocks, content types, taxonomies, hooks) are global
once a plugin is active on **any** tenant. That is stated in the README as a limitation,
not glossed over: they are read during rendering and validation where the registry was
already built.

## Capabilities without letting a plugin edit core

A plugin cannot edit Identity's `CapabilityCatalogue` — that is core. The seam is
inverted:

```text
Identity declares  ExtraCapabilityProviderInterface
Plugin platform implements it
Identity merges    extraCapabilities() + extraRoleGrants() when seeding
```

Identity never learns what a plugin is. Declared role grants are **defaults only**: once
a site has seeded, the stored assignment is authoritative, so a plugin update cannot
silently grant itself new permissions.

---

## The acceptance criterion, as a test

`theExamplePluginRegistersEveryContributionPoint` installs and activates the plugin,
boots a **fresh** application, and asserts all seven contributions exist:

| # | Contribution | Assertion |
|---|---|---|
| 1 | content type | `note` is registered |
| 2 | taxonomy | `note_channel` is registered |
| 3 | block | `note_callout` is registered |
| 4 | action | `note.created` is present |
| 5 | filter | `note.title` turns `Halo` into `Note: Halo` |
| 6 | API route | `/api/v1/example/notes/ping` is in the routing table |
| 7 | capability | `example.read_notes` is declared |

Nothing under `app/` was changed to make that pass.

---

## Defect found: test harness assumed fixed schema

Adding plugin tables to the truncation list broke **every** test in the suite, because
`example_notes` does not exist until a plugin is installed. The helper now checks
`information_schema` and skips a missing table. That is the correct behaviour: a test
database legitimately lacks plugin tables depending on what is installed.

A second issue was diagnostic rather than a product bug: plugin contributions are
registered at boot, so a test that installs a plugin after `setUp()` sees no route until
it boots a fresh application. The three route tests now do exactly that — which is also
what a real request does.

---

## Tests added

`PluginManagerTest` — 14 tests:

- discovery finds the example plugin, versions parse
- install runs the plugin's own migrations and creates its table
- install is idempotent; migrations are recorded once
- activation requires installation
- activation is per site; deactivation leaves it installed
- uninstall removes state but **keeps the plugin's data**
- an unknown plugin is rejected
- **the acceptance criterion** (all seven contribution points from a fresh boot)
- an inactive plugin's route answers 404
- an active plugin's route runs
- the route is not visible on another tenant

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (1006 tests, 3118 assertions)
```

Baseline 992 → 1006: **+14 tests**, none broken.

---

## Verified over real Apache HTTP

```
GET  /api/v1/plugins                          200  manifest, installed, active, active_sites
POST /api/v1/plugins/example-plugin/deactivate 200
GET  /api/v1/example/notes/ping               404  (gate closed)
POST /api/v1/plugins/example-plugin/activate   200
GET  /api/v1/example/notes/ping               200  {"plugin":"example-plugin","status":"ok"}
```

This also closes the `plugins` endpoint group that Agent 13 had left blocked.

---

## Known limitations

1. **No plugin admin UI.** The platform is driven by the API only.
2. **No dependency resolution.** `requires_plugins` is recorded but not enforced; a
   plugin whose dependency is missing still loads.
3. **No sandbox and no code signing.** A plugin runs as the host user with full PHP
   access. The task pack asks only that plugins not edit core, which the context
   enforces structurally — it is not a security boundary against a malicious plugin.
4. **Non-route contributions are global per active-anywhere plugin**, as described
   above. A block contributed by a plugin active only on Site A is still registered for
   Site B's renderer; it is only *used* if that site's content references it.
5. **`register()` must not perform I/O.** Documented, not enforced.
6. **No plugin update flow.** Changing a plugin's version on disk is picked up at
   discovery, but new migrations require an explicit reinstall.
7. **Hook registry is request-scoped**, so hooks added by a plugin do not persist into a
   cron run unless the plugin boots there too. `bin/cron.php` boots the application, so
   they do — but this is worth stating.
8. **No plugin-provided admin pages or settings screens.**

---

## CONTRACT_CHANGE_REQUEST

none

No frozen contract was modified. `ExtraCapabilityProviderInterface` is a **new** seam
declared by Identity, following the same pattern used for `MediaUsageCheckerInterface`
and `ScheduledContentFinderInterface`: the module that needs the capability declares the
interface, the module that owns the data implements it, and the dependency points
inward.

---

## Ready to merge

yes
