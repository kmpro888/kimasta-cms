# Agent 11 — Block Editor Engine — Implementation Report

Agent: Agent 11 — Block Editor Engine
Module: `CMS\Modules\Block`
Status: DONE

---

## Summary

Implemented the structured content system: a 20-block registry, schema-driven
sanitization, DOM-based HTML allowlisting, version migration, and a compiler that
produces HTML + plain text **without any database access**.

The engine plugs into Content by rebinding Content's frozen
`BlockCompilerInterface` — Content itself is unchanged.

Executed by the controller directly; see `Wave-4-Tracking.md`.

---

## Files added

```text
app/Modules/Block/BlockServiceProvider.php
app/Modules/Block/README.md

app/Modules/Block/Contracts/BlockRegistryInterface.php
app/Modules/Block/Contracts/BlockSanitizerInterface.php
app/Modules/Block/Contracts/BlockMigrationInterface.php
app/Modules/Block/Contracts/BlockRendererInterface.php

app/Modules/Block/Exceptions/UnknownBlockException.php
app/Modules/Block/Exceptions/InvalidBlockException.php

app/Modules/Block/Service/BlockRegistry.php
app/Modules/Block/Service/BlockSanitizer.php
app/Modules/Block/Service/BlockMigrator.php
app/Modules/Block/Service/BlockCompiler.php
```

No migration: block documents live in `contents.content_json`.

## Files modified

```text
app/bootstrap.php            provider registered LAST, so it can rebind the compiler
docs/reports/Wave-4-Tracking.md
```

---

## Contracts consumed

```text
CMS\Modules\Content\Contracts\BlockCompilerInterface   ← implemented, not consumed
```

## Contracts provided

`BlockRegistryInterface`, `BlockSanitizerInterface`, `BlockMigrationInterface`,
`BlockRendererInterface`, **and** the implementation of Content's frozen
`BlockCompilerInterface`.

---

## The integration that makes this module matter

Content writes `content_html` / `content_plain` through its own frozen
`BlockCompilerInterface`. Before this module, that was bound to Content's
`PlainBlockCompiler` fallback (paragraph/heading/list/quote/code/image only).

`BlockServiceProvider` rebinds it:

```php
$container->singleton(
    BlockCompilerInterface::class,
    fn (Container $c) => $c->get(BlockRendererInterface::class)
);
```

An integration test proves the difference: the same table block produces no
`<table>` through the fallback and a real `<table>` through the engine.

---

## Block shape and versioning

```json
{ "type": "paragraph", "version": 1, "data": { "text": "Hello" } }
```

`gallery` is registered at **version 2** (v1 `images` → v2 `media_ids`) precisely
so version migration is exercised against real old content rather than merely
claimed. `BlockMigrator` upgrades on read; migration is idempotent; an unknown
block type is preserved untouched so a disabled plugin cannot lose data.

---

## The renderer performs no I/O

Dynamic blocks (`embed`, `related_posts`, `ad_slot`) report themselves through
`dynamicBlockIndices()` and receive **pre-resolved** data keyed by block index:

```php
$compiler->render($blocks, [1 => [['title' => 'Other', 'url' => '/blog/other']]]);
```

Two independent checks:
- a structural test asserts the compiler constructor takes no repository,
  connection or PDO type;
- `render()` without prepared data still produces a valid page (dynamic blocks
  simply render nothing), so a missing resolver degrades instead of throwing.

---

## Security

| Risk | Control |
|---|---|
| Stored XSS via `html` block | DOM-based allowlist, not regex |
| `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, `<base>`, `<meta>` | dropped entirely |
| Inline `on*` handlers, `style`, `class`, `id` | stripped |
| `javascript:`, `data:`, protocol-relative `//host` | rejected |
| Obfuscated scheme (`java\nscript:`) | control characters in the scheme rejected |
| `target="_blank"` tabnabbing | forced `rel="noopener noreferrer"` |
| Undeclared `data` keys | removed by `sanitizeData()` before rendering |
| Text in every non-`html` block | HTML-escaped |

15 named XSS payloads are asserted in the security suite.

---

## Tests added

- unit: `BlockRegistryTest` (10), `BlockMigratorTest` (10),
  `BlockCompilerTest` (25 — every core block plus determinism and purity)
- contract: `BlockContractTest` (5)
- security: `BlockSanitizationTest` (47)
- integration: `BlockContentIntegrationTest` (4 — proves Content ↔ Block wiring)

## Tests passed

```text
PHPUnit 11.5.57 — PHP 8.3.6
OK (622 tests, 2082 assertions)
```

Block contributes 101 of those.

---

## Known limitations

1. **`html` block capability gating is not enforced here.** The sanitizer is
   unconditional, which is the safe default; the "special capability" half of the
   task pack's rule needs the Actor at the request layer and belongs to the admin
   controller (Agent 13).
2. `gallery` renders `<img data-media-id>` placeholders with an empty `src`. The
   media resolver that fills real variant URLs belongs to the theme/rendering
   layer, which is where media data is legitimately available.
3. `embed` accepts pre-resolved HTML from a resolver; the compiler trusts that
   resolver to have sanitized it. There is no second sanitization pass, by design —
   double-sanitizing would corrupt legitimate embed markup.
4. Nested blocks (`columns`, `section`) render children through the same renderer
   but do not support dynamic children yet; a `related_posts` nested inside a
   section renders nothing.
5. No admin editor UI; this wave ships the engine and contracts.

---

## CONTRACT_CHANGE_REQUEST

none

Content's `BlockCompilerInterface` was implemented as frozen, not modified.

---

## Ready to merge

yes
