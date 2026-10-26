# Plugin Module — Plugin Platform

## Ownership

Tables: `plugins`, `site_plugins`, `plugin_migrations`.
Directory: `/plugins`.

## The contract

A plugin is one class with one method:

```php
final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void { /* declare contributions */ }
}
```

Everything a plugin contributes goes through the context. It cannot obtain the
container, a connection, or another module's repository — that is what "plugins must
not edit core" means in practice, rather than a rule in a document.

## Lifecycle

```text
discovered  a directory with a valid plugin.json
installed   its migrations have run; recorded in `plugins`
active      enabled for one tenant; recorded in `site_plugins`
```

Installation and activation are deliberately separate. Installation runs schema
changes — an operator action. Activation is a per-site toggle an administrator can
flip.

**Uninstall does not drop a plugin's data.** Removing tables as a side effect of a
toggle is how people lose production data. The record and activation rows go; a table
created by the plugin's migration stays.

## Contribution points

| Kind | Method | Lands in |
|---|---|---|
| content type | `contentType()` | `ContentTypeRegistryInterface` |
| taxonomy | `taxonomy()` | `TaxonomyRegistryInterface` |
| block | `block()` | `BlockRegistryInterface` |
| action | `action()` | `HookRegistryInterface` |
| filter | `filter()` | `HookRegistryInterface` |
| API route | `route()` | `Router` (plus an activation gate) |
| capability | `capability()` | `PluginCapabilityRegistry` → Identity's seed |

## Actions vs filters

Two shapes for two questions, kept as separate methods because using an action where a
filter is needed is a common silent bug:

- **action** — "something happened". Return value ignored.
- **filter** — "here is a value, you may change it". Return value replaces it.

Higher priority runs first, matching the domain event dispatcher: one ordering rule
across the platform.

Every callback is recorded against its owning plugin, so deactivating a plugin removes
exactly its hooks.

## Per-tenant isolation of plugin routes

Routes must be registered at boot, before a tenant is known. Activation is per site. So
the decision is deferred: each plugin route is wrapped in a gate that resolves the host
and answers **404** when the plugin is inactive for that tenant.

404, not 403 — a 403 would confirm the endpoint exists.

Contributions other than routes (blocks, content types, taxonomies, hooks) are global
once a plugin is active on **any** tenant. That is a stated limitation, not an
oversight: they are read during rendering and validation, where the tenant is usually
available but the registry was already built.

## Capabilities

A plugin cannot edit Identity's `CapabilityCatalogue` — that is core. It declares its
capabilities here, and Identity merges them through
`ExtraCapabilityProviderInterface`, a seam **Identity** declares and the plugin
platform implements. The dependency points inward.

Declared role grants are defaults only. Once a site has seeded, the stored assignment
is authoritative, so a plugin update cannot silently grant itself new permissions.

## Discovery safety

A directory without a valid manifest is not a plugin. Missing version, invalid JSON, or
unreadable file: skipped, never fatal. One broken plugin must not take the site down.

The directory name is authoritative over the manifest `name`, so a copied plugin cannot
collide with the original.

## Not implemented

- No plugin admin UI; the platform is driven by the API and the console.
- No dependency resolution between plugins beyond recording `requires_plugins`.
- No code signing or sandbox: a plugin runs as the host user. The task pack asks only
  that plugins not edit core, which the context enforces.
- `PluginInterface::register()` must not do I/O; that is a documented rule, not an
  enforced one.
