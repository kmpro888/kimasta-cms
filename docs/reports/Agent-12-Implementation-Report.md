# Agent 12 — Theme & Frontend Rendering — Implementation Report

Agent: Agent 12 — Theme & Frontend Rendering
Module: `CMS\Modules\Theme`
Status: DONE

---

## Summary

Implemented theme discovery with manifest validation, the documented template
hierarchy with fallbacks, and an isolated view renderer — plus a real working
`themes/default` so discovery and rendering are exercised against the filesystem.

The structural rule from the task pack — **theme MUST NOT query database** — is
enforced by what the template can see, not by convention.

Executed by the controller directly; see `Wave-6-Tracking.md`.

---

## Files added

```text
app/Modules/Theme/ThemeServiceProvider.php
app/Modules/Theme/README.md

app/Modules/Theme/Contracts/ThemeRegistryInterface.php
app/Modules/Theme/Contracts/TemplateResolverInterface.php
app/Modules/Theme/Contracts/ViewRendererInterface.php

app/Modules/Theme/Entity/ThemeManifest.php
app/Modules/Theme/Entity/TemplateContext.php
app/Modules/Theme/Entity/ViewModel.php

app/Modules/Theme/Exceptions/ThemeNotFoundException.php
app/Modules/Theme/Exceptions/TemplateNotFoundException.php
app/Modules/Theme/Exceptions/InvalidThemeManifestException.php

app/Modules/Theme/Service/ThemeRegistry.php
app/Modules/Theme/Service/TemplateResolver.php
app/Modules/Theme/Service/ViewRenderer.php

themes/default/theme.json
themes/default/templates/index.php
themes/default/templates/single.php
themes/default/templates/archive.php
themes/default/templates/404.php
themes/default/partials/head.php
themes/default/partials/footer.php
themes/default/assets/theme.css
```

## Files modified

```text
app/bootstrap.php          provider registered
docs/reports/Wave-6-Tracking.md
```

---

## Contracts consumed

`SiteRepositoryInterface` (read `active_theme`, write it on activation),
`EventDispatcherInterface`, `ClockInterface`, `ConfigInterface`.

## Contracts provided

`ThemeRegistryInterface`, `TemplateResolverInterface`, `ViewRendererInterface`.

---

## Database migrations

None. A theme has no tables: the assignment is `sites.active_theme`, owned by Agent
02.

---

## Events emitted

| Event | When |
|---|---|
| `theme.activated` | after a theme is assigned to a site |

Payload carries `site_id`, `theme` and `previous_theme`, which is what lets the
cache evict pages that declared the old theme.

## Events consumed

None.

---

## API changes

None. The public controller that prepares ViewModels belongs to the frontend layer.

---

## Cache impact

`theme.activated` is the invalidation signal the cache layer consumes. Because every
rendered page declares `theme:{name}`, switching a theme evicts the site's pages and
nothing else.

---

## Multisite impact

- Theme assignment is per site (`sites.active_theme`), and a test asserts two sites
  can run different themes.
- `activate()` writes only the target site's row.
- A site assigned to an uninstalled theme falls back to `default` instead of failing.

---

## Security impact

| Risk | Control |
|---|---|
| Template sees the container | renderer passes only `$view` and `$renderer` |
| Template reaches a repository | `ViewModel` has no `db`/`pdo`/`query`/`container` accessor (asserted) |
| Partial path traversal | partial names with `..`, `/` or `\` are rejected |
| XSS in template output | `ViewModel::e()` is the provided escaping helper |
| Broken theme takes the site down | invalid manifest → theme skipped, not fatal |
| Copied theme shadowing another | directory name is authoritative over the manifest `name` |
| Half-rendered page on error | output is buffered; a failure cleans the buffer and rethrows |

---

## Template hierarchy

| Context | Candidates |
|---|---|
| single | `single-{type}.php` → `single.php` → `index.php` |
| page | `page.php` → `single.php` → `index.php` |
| archive | `archive-{type}.php` → `archive.php` → `index.php` |
| taxonomy / author / search | own file → `archive.php` → `index.php` |
| home | `home.php` → `index.php` |
| 404 | `404.php` → `index.php` |

`candidates()` is public so the choice is explainable. A custom post type gets its
own template for free by registering the type — `single-product.php` works with no
code change.

---

## Tests added

- unit: `ThemeRenderingTest` (15 — hierarchy at four fallback levels, candidate
  order, buffered rendering, escaping, `ViewModel::with`, partials, traversal
  rejection, unknown context)
- integration: `ThemeRegistryTest` (15 — discovery, invalid manifest skip, bad JSON
  skip, name mismatch, default fallback, activation persistence, event emission,
  per-site isolation, uninstalled-theme fallback)
- contract: `ThemeContractTest` (5)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (804 tests, 2636 assertions)
```

Theme contributes 30 of those.

---

## Known limitations

1. **No frontend controller in this wave.** The module provides registry, resolver
   and renderer; the code that builds ViewModels from Content/SEO/Cache and calls
   `render()` belongs to the public rendering layer. Without it, nothing is served
   from a theme yet.
2. **`functions.php` is not loaded.** The documented structure includes it; executing
   arbitrary theme PHP needs a sandboxing decision (capability gate + which hooks are
   exposed) that is out of scope here.
3. No template inheritance beyond `partial()`; a child theme does not override a
   parent's template.
4. Responsive image helpers are not implemented. The block engine emits
   `data-media-id` placeholders and the media variants exist, but the helper that
   builds `srcset` needs the media variant list in the ViewModel — a rendering-layer
   concern.
5. No admin theme browser; discovery is programmatic.

---

## CONTRACT_CHANGE_REQUEST

none

The frozen `SiteContextInterface` was **not** widened to expose `active_theme`.
Theme reads that field through the Site module's repository contract instead, which
keeps a display concern out of the tenant contract.

---

## Ready to merge

yes
