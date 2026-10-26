# Site Module — Multisite & Site Context

Owns tenant resolution and the three multisite tables. Every other module
depends on this one being correct, so the rules here are strict by design.

## Ownership

Tables: `sites`, `site_domains`, `site_settings`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `CMS\Shared\Contracts\SiteContextInterface` (frozen) | `Entity\Site` |
| `Contracts\SiteResolverInterface` | `Service\SiteResolver` |
| `Contracts\SiteServiceInterface` | `Service\SiteService` |
| `Contracts\SettingsServiceInterface` | `Service\SettingsService` |

## Resolution order

```text
Host header
  → HostNormalizer::normalize()      reject anything that is not a DNS hostname
  → DomainMap::siteIdFor()           compiled PHP file, opcache-backed, 0 queries
  → DomainRepository::findSiteId...  database fallback on a miss
  → SiteRepository::findById()       build the immutable Site context
```

**There is no default site.** An unresolvable host raises
`SiteNotFoundException` (`SITE_NOT_FOUND`, HTTP 404). There is no code path
that assumes `site_id = 1`; a test asserts that explicitly, including the case
where ids 1 and 2 both exist.

## Compiled caches

| File | Written by | Read by |
|---|---|---|
| `storage/cache/system/domain-map.php` | `DomainMap::compile()` after any mapping change | `SiteResolver` on every request |
| `storage/cache/system/site-settings-<id>.php` | `SettingsService` after any setting write | `SettingsService::autoloaded()` |

Both are written atomically (temp file + rename) and the opcache entry is
invalidated, so a change is visible on the very next request. Both are
regenerated on write, never on read.

## Security rules

- Malformed or hostile `Host` values are rejected, never coerced. Control
  characters are checked on the **raw** value before trimming, because `trim()`
  would otherwise strip a trailing NUL or CRLF into an acceptable host.
- A domain is globally unique at the database level, so one host can never be
  steered onto two tenants.
- `site_id` is never accepted from tenant request input.
- Settings reads and writes always take an explicit site id.

## Extension points for later modules

- Call `SiteResolverInterface::resolve($host)` in the request pipeline.
- Call `SettingsServiceInterface::get($siteId, $key, $namespace, $default)` with
  the id taken from the resolved context, never from request input.
