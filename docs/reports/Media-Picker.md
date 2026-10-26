# Media Picker

The editor could write an article but not attach an image to it: the image block took a URL
typed by hand, and the featured-image field was not exposed at all. `POST /api/v1/media` existed
but had no screen. This closes that gap.

---

## What a person can do now

```text
/admin/media                        the library: grid, upload form, delete
/admin/media?pick=featured&content_id=N     pick an image for the featured field
/admin/media?pick=block&content_id=N&index=K   pick an image for one block
```

- Upload a file, with alternative text, from the library page.
- Set, replace or remove the featured image from the article editor.
- Turn any block into an image block by choosing a file from the library.
- Delete an image that nothing uses.

---

## Decisions

**No JavaScript, again.** Choosing an image is an ordinary form post that names its target.
The picker carries its context in the query string (`pick`, `content_id`, `index`), the apply
button posts to the matching action, and the redirect returns to the editor. Nothing about the
flow needs scripting, which is why it is testable through HTTP.

**The picker applies the choice on the server.** The alternative — remember a selection in the
session and apply it when the article is saved — would break the moment someone opens two tabs.

**Tenant is verified, not trusted.** Every media id posted by a form is resolved through
`MediaService::get($id, $siteId)`. A guessed id from another site is a 404, not a 403: confirming
that the row exists would leak the other tenant's library.

**Deleting is refused while an article still points at the file.** `MediaService::delete()`
already enforced this; the screen turns `MediaInUseException` into a redirect with a message,
because a 500 in front of a button is not an explanation.

**Stored URLs are site-relative** (`/uploads/...`). The same row then renders correctly on any
host, which matters for a CMS whose whole point is serving several domains.

---

## Two gaps this work found

**1. The blank trailing block row could never accept an image.**

The editor always renders one empty block so a new one can be typed. Its index equals the
current block count, but no such block exists in `content_json`, so "choose an image" for that
row looked up a block that was not there and failed with "that block no longer exists". The last
row of the editor was the one thing that could not take a picture.

`blockImage()` now treats `index === count($blocks)` as **append** and any other unknown index as
an error. A test pins both.

**2. The featured image never reached the page.**

`PageViewModelFactory` has resolved `featured_image` since the theme was built — url, alt, width,
height — and `single.php` never printed it. So attaching an image changed nothing visible on the
site. That is the same shape of defect as the analytics snippet found in Wave 9: the contract
existed, the consumer did not.

`single.php` now renders it as a `<figure>` with the alt text and explicit dimensions, so the
layout does not jump while the image loads.

---

## Files

```text
app/Modules/Admin/Http/Controller/MediaController.php   NEW  library, upload, delete
app/Modules/Admin/Http/Controller/ContentController.php      featured(), blockImage(), saveFields()
app/Modules/Admin/Resources/views/media/index.php       NEW  grid, upload form, picker
app/Modules/Admin/Resources/views/content/form.php           featured panel, block picker links
app/Modules/Admin/Resources/views/layout.php                 Media in the navigation
app/Modules/Admin/AdminServiceProvider.php                   4 routes, 1 binding
public/assets/admin.css                                      media grid, thumbnails, danger button
themes/default/templates/single.php                          renders the featured image
themes/default/assets/theme.css                              figure styles
tests/Support/AdminTestCase.php                              post() accepts $_FILES
tests/Integration/Modules/Admin/AdminMediaTest.php      NEW  15 tests
```

---

## Tests

| Covers | |
|---|---|
| library | requires sign-in, lists stored images with dimensions, upload form present |
| upload | succeeds through multipart; no file is a 422 with a message; an unsupported type is refused and stores nothing |
| permissions | a contributor may upload and read but is refused delete (403); an editor may delete |
| in use | an image referenced by an article is not deleted, and the redirect carries a message |
| picker | "Use this image" only appears in pick mode; "Back to the article" is offered |
| featured | set, shown as a thumbnail in the editor, cleared back to null |
| cross-tenant | a foreign media id is a 404 on both the featured and the block path |
| blocks | an image replaces a block; the blank trailing row appends; an impossible index writes nothing |
| public page | the featured image, its alt text and the stored path appear in the rendered article |

```text
PHPUnit 11.5.57 - PHP 8.3.6
OK (1211 tests, 3854 assertions)
```

Baseline before this work: 1196 tests. +15, none broken.

---

## Verified by hand, over Apache

```text
GET  /admin/media                     200, upload form and navigation present
POST /admin/media  (multipart)        303, row created: pick.png, alt "Gambar uji", 48x36
GET  /admin/media?pick=featured...    200, "Use this image" per image
POST /admin/content/8/featured        303, contents.featured_media_id = 3
POST /admin/content/8/block-image     303, block 1 became {"type":"image","url":"/uploads/..."}
GET  /admin/content/8                 200, thumbnail and the block's url field render
```

Test rows were removed afterwards and the page cache flushed through the application.

---

## Known limitations

1. **One file at a time.** No multi-select, no drag and drop, no folder.
2. **No cropping or resizing.** Variants are generated by the existing pipeline; the picker
   always inserts the original.
3. **No alt text editing in the library.** Alt is set at upload and copied into the block; to
   change it you edit the article's block field.
4. **No pagination control.** The library shows the first 24 files and there is no pager yet.
5. **No unused-media cleanup.** An orphaned file stays until someone deletes it.
6. **Blocks cannot reference a media id**, only a URL. If a file is later deleted while an
   article's block still points at it, the block renders a broken image: the usage check covers
   `featured_media_id`, not image blocks.
7. **No SVG or video handling in the picker UI** beyond what the service already accepts;
   "Use this image" is only offered for `image/*`.

Limitation 6 is worth fixing before anyone deletes images in anger; it needs the block json to
carry `media_id` and the usage checker to read it.

---

## CONTRACT_CHANGE_REQUEST

none

---

## Ready to merge

yes
