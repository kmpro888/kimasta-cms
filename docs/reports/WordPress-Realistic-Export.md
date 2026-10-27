# WordPress Migration Against a Realistic Export

The importer had only ever been tested against a fixture written by the same hand that wrote the
importer. That proves the code agrees with itself. This round replaced it with an export shaped like
what an operator actually hands over, and the shape found four defects — all of them silent data
loss.

---

## The four defects

| | What happened | Why it mattered |
|---|---|---|
| **Category hierarchy flattened** | `wp_term_taxonomy.parent` was read and then passed as `null` to `createTerm` | Every category became a root. A three-level tree arrived as three siblings. |
| **Comment threads flattened** | `wp_comments.comment_parent` was read and never passed to `submit` | Replies became separate top-level comments. The conversation lost its order. |
| **Guest comments dropped** | The importer required the commenter to have an account | WordPress has many guest commenters; the platform now accepts anonymous comments, so dropping them was no longer justified — and the author name WordPress stored was thrown away. |
| **`wp_usermeta` / `wp_termmeta` never read** | Nothing looked at them | Capabilities, nicknames and term thumbnails vanished with no line in the report saying so. |

All four are fixed:

- the parent is resolved through the migration map, and a parent that is not there yet is logged
  rather than silently dropped;
- `comment_parent` is resolved the same way, with a depth-limit fallback that keeps the comment at
  the root and says so;
- guests are imported with `user_id = 0` and their `comment_author` as the display name, keeping
  WordPress's approval state;
- `userMeta()` and `termMeta()` read the tables and every unconsumed key is reported, matching the
  policy post meta already had.

---

## The fixture

Two blogs' worth of tables in one database (`wp_` and `wp_2_`), plus `wp_site` and `wp_blogs`, which
is how a multisite export actually looks:

```text
posts        1 published post, 1 page with a child page, 1 attachment, 1 revision,
             1 nav_menu_item, 1 draft
terms        News -> Politics -> Election  (three levels, real parents)
             1 tag
meta         wp_postmeta (Yoast title, robots, an unknown plugin key)
             wp_usermeta (capabilities, nickname)
             wp_termmeta (thumbnail_id)
comments     100 (registered) -> 101 (reply, registered) -> 102 (reply, guest)
network      wp_site, wp_blogs, and a second blog under wp_2_
```

---

## What the tests prove

| | |
|---|---|
| hierarchy | `News` is a root, `Politics` is its child, `Election` is a grandchild — asserted through `listTerms` by parent, not by reading the fixture |
| thread | the three comments arrive with depths `[0, 1, 2]` and the right bodies, in order |
| guests | exactly one comment has `user_id = 0` and keeps `A Guest` as its author |
| meta | the report contains `wp_capabilities` and `thumbnail_id` as not carried across |
| multisite | `wp_2_` imports into a second site, and the first site does not receive it |
| row types | revision, nav menu item and attachment do not become posts or pages; the draft arrives **as a draft** |

The first version of the draft assertion was wrong: it treated the draft as rubbish to be excluded.
A draft is content somebody was working on; the importer keeps it, with its status.

---

## Files

```text
app/Modules/Migration/Service/MigrationService.php   term parent, comment parent, guest comments,
                                                    logUncarriedMeta()
app/Modules/Migration/Source/WordPressSource.php     userMeta(), termMeta(), metaFor()
tests/Integration/Modules/Migration/RealisticExportTest.php  NEW  6 tests
tests/Integration/Modules/Migration/WordPressMigrationTest.php  the guest-comment test updated
```

---

## Verified

```text
php vendor/bin/phpunit --filter 'RealisticExportTest|WordPressMigrationTest'
OK (24 tests, 103 assertions)
```

```text
php vendor/bin/phpunit
OK (1407 tests, 4459 assertions)
```

---

## Known limitations

1. **Attachments are not imported without their files.** The importer needs the uploads directory;
   the fixture has no binary files, so media rows are logged as "file not found" rather than created
   with a broken image. That is deliberate and tested elsewhere.
2. **Nav menu items are not migrated.** They are a navigation structure this platform builds from
   taxonomies and pages; importing them would need a menu system that does not exist.
3. **`wp_usermeta` values are reported, not mapped.** WordPress capabilities do not translate to this
   platform's capability catalogue; the operator sees what did not come across.
4. **Comment meta, term meta values and options beyond `permalink_structure` are not migrated.**
5. **One depth limit.** WordPress threads deeper than this platform allows are moved to the root and
   logged, not truncated.
6. **No incremental re-import test for the new mappings.** Re-running is idempotent by the existing
   map table, but the multisite and threading paths were not replayed twice in this round.
7. **The fixture is synthetic.** It is shaped after a real export (row types, multisite tables,
   hierarchy depth) but it is not somebody's actual database; a dump from a real site is still the
   only way to meet the unknown-unknowns.

---

## Ready to merge

yes
