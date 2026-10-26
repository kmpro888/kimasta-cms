# Media References in Article Bodies

The media library refused to delete a file used as an article's featured image, and happily
deleted a file the article was displaying inside its body. Deleting that file left a broken
image on a published page, with nothing to warn anyone.

This closes the last known way that routine admin work could damage live content.

---

## The defect

`ContentMediaUsageChecker` counted one thing:

```sql
SELECT COUNT(*) FROM contents WHERE site_id = :site_id AND featured_media_id = :media_id
```

But an article references a file in **two** ways:

| Where | Stored as |
|---|---|
| featured image | `contents.featured_media_id` (an id) |
| image inside the body | a block's `data.url` in `content_json` (a path) |

An article with an image in its body and no featured image returned `0` references, so the
delete went through and the page started serving a broken image.

---

## The fix

The checker now counts both. Media already has the row loaded when it deletes, so it passes the
file's path along:

```sql
SELECT COUNT(*) FROM contents
WHERE site_id = :site_id
  AND (featured_media_id = :media_id OR content_json LIKE :path_match)
```

### Two decisions worth explaining

**Matched by path, not by id.** Image blocks have never carried a `media_id`; they carry the URL
the page renders. Adding an id now would not help the articles already written, and matching the
path covers both old and new rows without a backfill that would have to guess. The stored path
contains a random suffix, so a substring match cannot collide with another file.

**The interface gained an optional parameter.**

```php
countReferences(int $mediaId, int $siteId, ?string $path = null): int
```

Content cannot look the path up itself: the `media` table belongs to Media, and reaching into it
would break the boundary this interface exists to protect. Passing the path is the only shape
that keeps the dependency direction intact. The parameter is optional, so an implementation that
only knows ids stays valid.

---

## Files

```text
app/Modules/Media/Contracts/MediaUsageCheckerInterface.php   optional $path, and why
app/Modules/Media/Support/NullMediaUsageChecker.php          signature
app/Modules/Media/Service/MediaService.php                   passes $media->path
app/Modules/Content/Support/ContentMediaUsageChecker.php     counts body references
tests/Integration/Modules/Content/ContentMediaUsageCheckerTest.php   NEW, 8 tests
tests/Integration/Modules/Admin/AdminMediaTest.php           +2 tests, and a cached uploader
tests/Integration/Modules/Media/MediaServiceTest.php         test doubles updated for the signature
```

---

## Tests

`ContentMediaUsageCheckerTest` (8) pins the answer Content gives Media:

- an unused file has no references
- a featured image counts
- **an image inside the body counts** (the defect)
- the same file twice in one article counts as one row
- two articles count as two
- another tenant's article is not counted
- without a path, only the featured reference is visible
- an unrelated path sharing a prefix does not match

`AdminMediaTest` (+2) pins the behaviour a person sees:

- deleting a file displayed inside an article is refused, and the file survives
- once the article stops using it, deletion succeeds

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1221 tests, 3866 assertions)
```

Baseline before this fix: 1211 tests. +10, none broken.

---

## Why this mattered more than the next feature

The scheduling UI is a missing feature: someone notices it is absent and works around it. This
was the opposite shape — a working delete button that silently broke a published page, and the
damage appears to a reader, not to the person who clicked. Bugs that only surface to visitors are
the ones worth fixing first.

---

## Known limitations

1. **A `LIKE` scan per delete.** Deletion is rare and the table is indexed by site, so this is
   acceptable; a full-text or JSON index would be needed if it ever became a hot path.
2. **Substring matching, not structural.** It matches the path anywhere in `content_json`, so a
   block whose *alt text* happened to contain the path would also count. That errs toward
   protecting the file, which is the safe direction.
3. **Revisions are not searched.** Old revisions may still mention a deleted file; they are not
   rendered publicly.
4. **A forced delete still bypasses the check** (`delete($id, $siteId, true)`), which is
   intentional: it is the recovery path, and it leaves broken images behind by design.
5. **Blocks still do not carry a media id**, so no screen can show "this block uses file #12" or
   sync alt text between the library and the article. That is a design gap, not a bug; it would
   need the block schema and the form compiler to change together.

---

## CONTRACT_CHANGE_REQUEST

none

`MediaUsageCheckerInterface` is not one of the frozen contracts listed in `AGENTS.md`, and the
change is additive: a new optional parameter with a default. Every existing implementation and
call site remains valid; the only ones that had to change were the two test doubles whose
signatures would otherwise have been incompatible.

---

## Ready to merge

yes
