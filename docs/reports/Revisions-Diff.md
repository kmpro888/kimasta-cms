# Revision Diff

Revisions were written on every save and listed in the admin, and reviewing one meant **restoring it
and looking**. There was no way to see what changed.

---

## What it does

`RevisionService::diff($revisionId, $siteId, ?$againstId)` compares a revision with the one before it
(or with a revision the caller names) and returns:

```php
[
    'title'   => ['from' => '…', 'to' => '…'],
    'excerpt' => ['from' => '…', 'to' => '…'],
    'blocks'  => [['index' => 0, 'status' => 'changed', 'from' => '…', 'to' => '…']],
    'changed' => true,
]
```

**Block-level, not character-level.** The editor stores blocks; a paragraph that lost a comma is not
a different paragraph, and a character diff of rendered HTML would be unreadable. A block is
`changed`, `added` or `removed`, and its text is whatever the block's `data` contains — flattened
recursively, because an unknown block type must not read as "no change".

A revision identical to its predecessor reports `changed: false`, which is worth saying: an editor who
saved without editing produces one.

---

## The screen

`/admin/content/{id}/revisions/{revision}` — **before** and **after** side by side, per changed field
and per changed block, with a *Compare* link on every row of the revision list.

---

## The tests

| | |
|---|---|
| a changed block | one block reported as `changed`, with the new text |
| an identical revision | `changed: false`, no blocks |
| the screen | renders `Compare revision`, the new text, and `<del>` / `<ins>` |

```text
php vendor/bin/phpunit --filter RevisionDiffAndAtomTest
OK (5 tests, 23 assertions)
```

The screen test asserts the markers rather than the styling: the diff is a reading aid, and a
stylesheet change must not be able to make it pass.

---

## Known limitations

1. **No inline word-level highlighting.** The whole block is shown before and after; a large
   paragraph with one changed sentence is still a large paragraph twice.
2. **Blocks are aligned by index.** Reordering blocks reports them as changed rather than moved.
3. **No three-way compare** and no diff against the current draft — only revision-against-revision.
4. **No diff of taxonomy, SEO fields or media**; only title, excerpt and block text.
5. **Unavailable for very old revisions** whose predecessor was pruned, if a retention policy is ever
   added — it would then report an empty "before".

---

## Ready to merge

yes
