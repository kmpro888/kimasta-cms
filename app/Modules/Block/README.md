# Block Module — Block Editor Engine

Owns structured content: block types, validation, sanitization, versioning and
compilation to HTML.

## Ownership

`app/Modules/Block/**`. No tables — block documents live in `contents.content_json`.

## Contracts provided

| Contract | Implementation |
|---|---|
| `Contracts\BlockRegistryInterface` | `Service\BlockRegistry` |
| `Contracts\BlockSanitizerInterface` | `Service\BlockSanitizer` |
| `Contracts\BlockMigrationInterface` | `Service\BlockMigrator` |
| `Contracts\BlockRendererInterface` | `Service\BlockCompiler` |
| `Content\Contracts\BlockCompilerInterface` (frozen) | `Service\BlockCompiler` |

## It plugs into Content by rebinding one line

Content stores `content_html` / `content_plain` through its own frozen
`BlockCompilerInterface`. This module binds that interface to the real engine:

```php
// BlockServiceProvider
$container->singleton(
    BlockCompilerInterface::class,
    fn ($c) => $c->get(BlockRendererInterface::class)
);
```

Content is unchanged. An integration test proves the stored HTML changes when the
binding changes.

## Block shape

```json
{ "type": "paragraph", "version": 1, "data": { "text": "Hello" } }
```

20 core blocks are registered: paragraph, heading, image, gallery, quote, list,
table, button, embed, video, code, html, columns, section, hero, cta, faq,
accordion, related_posts, ad_slot.

## Pipeline

```text
migrate (old versions) → validate (known type) → sanitize (declared keys)
→ render (pure) → html + plain
```

Unknown types are skipped at render time but preserved in storage, so a
temporarily disabled plugin does not lose its data or break the page.

## The renderer does not touch the database

Dynamic blocks (`embed`, `related_posts`, `ad_slot`) declare themselves dynamic and
receive **pre-resolved** data keyed by block index:

```php
$compiler->render($blocks, [1 => [['title' => 'Other', 'url' => '/blog/other']]]);
```

`dynamicBlockIndices()` tells the caller which blocks need preparation. A
structural contract test asserts the compiler's constructor has no repository,
connection or PDO dependency.

## Security

- Every non-`html` block escapes its output.
- The `html` block passes through a **DOM-based allowlist sanitizer**, not a regex
  one. Script/style/iframe/object/embed/form/base/meta are dropped, event handlers
  and inline styles are stripped, and `javascript:`/`data:`/protocol-relative URLs
  are rejected. `target="_blank"` is forced to `rel="noopener noreferrer"`.
- Undeclared `data` keys are removed before rendering, so a crafted editor payload
  cannot smuggle an unexpected attribute into stored content.

## Versioning

`gallery` is at schema v2 (`images` → `media_ids`). Old v1 documents migrate on
read, so stored content survives a schema change. Migration is idempotent.
