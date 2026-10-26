# Theme Module — Theme System & Public Rendering

Owns theme discovery, template resolution and the view renderer for the public
site.

## Ownership

`app/Modules/Theme/**`, `themes/**`, public frontend rendering.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\ThemeRegistryInterface` | `Service\ThemeRegistry` |
| `Contracts\TemplateResolverInterface` | `Service\TemplateResolver` |
| `Contracts\ViewRendererInterface` | `Service\ViewRenderer` |

## Theme structure

```text
/themes/{name}/
  theme.json      manifest (name, version, optional title/description/author)
  templates/      page templates
  partials/       reusable fragments
  assets/         css, images
```

A directory without a valid `theme.json` is simply **not** a theme. A manifest
without `version` or with invalid JSON is skipped, not fatal — one broken theme
must not take the site down. The directory name is authoritative over the `name`
field, so a copied theme cannot collide with its original.

## Template hierarchy

| Context | Candidates (first match wins) |
|---|---|
| single | `single-{type}.php` → `single.php` → `index.php` |
| page | `page.php` → `single.php` → `index.php` |
| archive | `archive-{type}.php` → `archive.php` → `index.php` |
| taxonomy / author / search | `taxonomy.php` / `author.php` / `search.php` → `archive.php` → `index.php` |
| home | `home.php` → `index.php` |
| 404 | `404.php` → `index.php` |

`candidates()` is public so an admin can explain *why* a template was chosen.

## Themes cannot query the database

This is enforced structurally, not by convention:

- `ViewRenderer` receives only a `ThemeRegistryInterface`.
- A template is evaluated in an isolated closure with exactly two variables:
  `$view` (the `ViewModel`) and `$renderer` (for partials).
- `ViewModel` has no `db`, `pdo`, `query`, `container` or `repository` accessor.
- A contract test asserts all of the above.

Everything a template needs must arrive pre-prepared in the ViewModel.

## Escaping

`ViewModel::e()` is the only escaping helper, so a template that uses it cannot
forget `htmlspecialchars`. `content_html` is the one value printed raw, because the
block engine already compiled and sanitized it at save time.

## Activation

`activate()` writes the site's `active_theme` through the Site module's repository
contract and emits `theme.activated`, which the cache layer consumes to evict pages
that declared the old theme.

A site assigned to a theme that has since been removed falls back to `default`
rather than failing to render.

## Default theme

`themes/default` is a real, working theme (head/single/archive/404 + CSS, no
JavaScript) and the fallback for any site without an assignment. It exists so theme
discovery and rendering are testable against the filesystem rather than a mock.
